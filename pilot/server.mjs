import {capturePreview,previewTool} from "./browser-worker.mjs";
import {knowledge,extractFile} from "./knowledge.mjs";
import http from "node:http";
import {randomBytes, createHash, timingSafeEqual} from "node:crypto";
import {readFile} from "node:fs/promises";
import {loadEngine, runEngineStep} from "../../skeeks-ai-runner/src/engine.mjs";

// Stateless executor of SkeekS AI runs. cms-job hands over one run with a REST token
// of the employee; chats, runs and events live in the site's database.
const crm = process.env.SKEEKS_AI_SITE_URL || "http://localhost:8080";
const resource = crm + "/cms/rest-api";
const random = () => randomBytes(32).toString("base64url");
const defaultEngineId=process.env.SKEEKS_AI_ENGINE ?? "codex";
if(defaultEngineId==="claude")throw Error("Claude на стенде требует привязки профиля к пользователю и чату.");
const defaultEngine=await loadEngine(defaultEngineId);
const claudeOwner=Number(process.env.SKEEKS_AI_CLAUDE_OWNER);
const claudeEngine=claudeOwner ? await loadEngine("claude") : null;
function enginesFor(owner){
  return claudeEngine && Number(owner)===claudeOwner ? [defaultEngine,claudeEngine] : [defaultEngine];
}
const bridgeKey = (await readFile(process.env.SKEEKS_AI_STORE_KEY_FILE,"utf8")).trim();

async function json(url, options={}) {
  if (new URL(url).origin !== crm) throw new Error("Некорректный адрес стенда.");
  const response = await fetch(url,{...options,redirect:"error",signal:AbortSignal.timeout(20000)});
  if (!response.ok){
    const error=new Error("CRM отклонила запрос (HTTP " + response.status + ").");
    error.status=response.status;
    if(response.status===422 && url.startsWith(resource+"/tools/cms_tree_resolve")){
      error.treeMissing=(await response.text()).includes("cms_tree not found");
    }
    throw error;
  }
  return response.json();
}
async function api(s,path,args,headers={}) {
  const data = await json(resource+path,{headers:{
    ...headers,authorization:"Bearer "+s.token,"content-type":"application/json"
  },...(args===undefined?{}:{method:"POST",body:JSON.stringify(args)})});
  if (!data.success) throw new Error("Операция отклонена CRM.");
  return data;
}
function compact(data) {
  const value=JSON.stringify(data,(k,v)=>/password|secret|credential|access_token|refresh_token|check_token|api_key|private_key/i.test(k)?"[скрыто]":typeof v==="string"?v.slice(0,1200):Array.isArray(v)?v.slice(0,12):v);
  return value.length>7200?value.slice(0,7200)+"…":JSON.parse(value);
}
async function store(s,op,fields={}) {
  return json(crm+"/ai-chat-store/index",{method:"POST",headers:{
    authorization:"Bearer "+s.token,"content-type":"application/json","x-skeeks-ai-bridge":bridgeKey
  },body:JSON.stringify({op,id:s.chatId,...fields})});
}
/** Progress goes to the run's event stream in order; a stop request is noticed on the next event. */
function progress(s){
  let queue=Promise.resolve();
  return {push(text,type="step"){
    queue=queue.then(()=>store(s,"agentEvent",{...s.ref,text,type,acceptsNotes:true})).then(r=>{
      if(r.cancel)s.cancel=true;
      if(Array.isArray(r.notes) && r.notes.length)(s.notes??=[]).push(...r.notes);
    }).catch(()=>{});
  },flush(){return queue;}};
}
// Human step labels for the chat: what the agent looks at, not the operation name.
const subjects=[["cms_ai_project_files","файлы проекта"],["cms_ai_current_user","ваш профиль"],["cms_content_element","публикации"],
  ["cms_crm_history","историю клиента"],["cms_crm_client","обзор клиентов"],["cms_comm_chat","чаты"],["cms_comm_draft","черновики писем"],
  ["cms_comm","переписку"],["cms_tree","разделы сайта"],["cms_task","задачи"],["cms_company","компании"],["cms_lead","сделки"],
  ["cms_deal","услуги"],["cms_project","проекты"],["cms_worker","сотрудников"],["cms_user","пользователей"],["cms_proposal","предложения"],
  ["cms_telephony","звонки"],["cms_sms","SMS"],["cms_storage","файлы"],["shop_bill","счета"],["shop_order","заказы"],
  ["shop_payment","платежи"],["shop_","магазин"],["site_template","шаблоны страниц"]];
function stepLabel(name){
  const subject=subjects.find(([prefix])=>name.startsWith(prefix))?.[1];
  if(!subject)return "Обращаюсь к CRM: "+name;
  if(/_stats$|_count$/.test(name))return "Считаю "+subject;
  if(/_resolve$|_search$|_duplicate_check$/.test(name))return "Ищу "+subject;
  return "Смотрю "+subject;
}
class Canceled extends Error {}
/**
 * A stateless engine gets the whole context with every call: the request, the steps of this run and the
 * recent conversation. Resuming a Codex thread re-adds its own prompt (~18k tokens) on every call,
 * so each step of a run and each message of a chat grew more expensive.
 */
function statelessTurn(s, turn, request, steps) {
  const conversation = (s.history || []).slice(-16).map(m => ({role: m.role, text: String(m.text).slice(0, 1500)}));
  let kept = [], size = 0;
  for (const step of [...steps].reverse()) {
    const length = JSON.stringify(step).length;
    if (size + length > 40000 && kept.length) break;
    kept.unshift(step); size += length;
  }
  return {...request, conversation, stepsOfThisRequest: kept, omittedEarlierSteps: steps.length - kept.length,
    ...(turn !== request ? {protocol: turn.protocol} : {})};
}
async function chatTurn(s, turn, image, request = turn, steps = []) {
  const binding = createHash("sha256").update(resource + "|" + s.chatId).digest("hex");
  const stateless = s.engine.stateless === true;
  if (stateless) turn = statelessTurn(s, turn, request, steps);
  const result = await runEngineStep(s.engine, {
    binding, sessionId: stateless ? null : s.engineSession, turn, image, history: s.history, request, steps, model: s.model || undefined, effort: s.effort || undefined
  });
  if (!stateless && s.engineSession === null && result.sessionId !== null) {
    await store(s, "bindEngine", {engine: s.engine.id, session: result.sessionId});
    s.engineSession = result.sessionId;
  }
  // Token usage of the run: summed over model calls; cost only when the engine reports it for every call.
  const u = result.usage, total = s.usage ??= {input:0,cached:0,output:0,calls:0,tools:0,model:"",costUsd:0,costKnown:true};
  total.calls++;
  if (u && typeof u === "object") s.lastCall = {input: Math.max(0, u.input|0), cached: Math.max(0, u.cached|0)};
  // Per-call usage for diagnostics (not shown in the chat).
  if (u && typeof u === "object") s.progress.push("call "+total.calls+": in="+(u.input|0)+" cached="+(u.cached|0)+" out="+(u.output|0)+" sent="+JSON.stringify(turn).length+" chars","usage");
  if (u && typeof u === "object") {
    for (const k of ["input","cached","output"]) total[k] += Math.max(0, Number(u[k]) || 0);
    if (typeof u.model === "string" && u.model) total.model = u.model.slice(0,100);
    if (typeof u.costUsd === "number") total.costUsd += u.costUsd; else total.costKnown = false;
  } else total.costKnown = false;
  return result.step;
}
async function loadChat(s,id){
  const chat=await store(s,"get",{id});
  s.siteId=Number(chat.site_id);
  s.engineId=chat.engine;
  s.engine=enginesFor(chat.owner_id).find(engine=>engine.id===chat.engine);
  s.chatId=Number(chat.id);s.engineSession=chat.engine_sessions[chat.engine] ?? null;s.catalogRevs=chat.catalog_revs ?? {};s.history=chat.history;s.files=chat.files;
  s.company=Number(chat.company_id)||null;s.pending=chat.pending;
}
const gatewayTreeTools=new Set(["cms_tree_get","cms_tree_resolve","cms_tree_list","cms_tree_create","cms_tree_update"]);
const templatesTool={name:"site_template_examples",required_scope:"cms.tree.read",
  description:"Найти реально используемые шаблоны и ID опубликованных примеров для оформления новой страницы в стиле этого сайта.",
  inputSchema:{type:"object",additionalProperties:false,properties:{parent_id:{type:"integer",minimum:1}}}};
async function templateExamples(s,parent){
  if(parent!==undefined && (!Number.isSafeInteger(parent)||parent<1))throw Error("Некорректный раздел.");
  const groups=new Map();
  let offset=0,total;
  do{
    const filters={cms_site_id:s.siteId,active:"Y",limit:100,offset};
    if(parent!==undefined)filters.pid=parent;
    const {data}=await api(s,"/tools/cms_tree_list",filters);
    total=data.total;
    for(const page of data.items){
      if(!page.view_file)continue;
      const key=JSON.stringify([page.pid,page.view_file]);
      const group=groups.get(key) || {parent_id:page.pid,view_file:page.view_file,uses:0,examples:[]};
      group.uses++;
      if(group.examples.length<3)group.examples.push(page.id);
      groups.set(key,group);
    }
    offset+=data.items.length;
    if(!data.items.length)break;
  }while(offset<total && offset<1000);
  return {templates:[...groups.values()].sort((a,b)=>b.uses-a.uses),scanned:offset,total};
}
const styleTool={name:"page_css_update",required_scope:"cms.tree.write",description:"Изменить только CSS в HTML-описании раздела, сохранить весь текст и цену. Требует подтверждения.",
  inputSchema:{type:"object",required:["id","css"],additionalProperties:false,properties:{id:{type:"integer",minimum:1},css:{type:"string",maxLength:12000}}}};
async function confirmedTool(s,p,headers){
  if(p.tool!==styleTool.name)return (await api(s,"/tools/"+p.tool,p.args,headers)).data;
  const {id,css}=p.args;
  if(!Number.isSafeInteger(id)||!id||typeof css!=="string"||!css.trim()||css.length>12000||/[<>]|@import|url\s*\(/i.test(css))throw Error("Проверьте CSS раздела.");
  const page=(await api(s,"/tools/cms_tree_get",{id})).data;
  if(!page || typeof page.description_full!=="string" || page.description_full_type!=="html")throw Error("Для CSS требуется HTML-описание раздела.");
  const body=page.description_full.replace(/<style data-skeeks-ai-page>[^<]*<\/style>\s*/g,"");
  const description_full="<style data-skeeks-ai-page>"+css+"</style>\n"+body;
  return (await api(s,"/tools/cms_tree_update",{id,attributes:{description_full}},headers)).data;
}
function gatewayTreeResult(record){
  const structure=String(record.description_full ?? "").match(/<(?:section|h[1-4]|div|ul|p)\b/gi) || [];
  const layoutClasses=Array.from(String(record.description_full ?? "").matchAll(/class=["']([a-zA-Z0-9_ -]{1,100})["']/g),m=>m[1]);
  return {id:record.id,parent_id:record.pid,cms_site_id:record.cms_site_id,tree_type_id:record.tree_type_id,
    published:record.published,view_file:record.view_file,structure:structure.slice(0,60),layoutClasses:[...new Set(layoutClasses)].slice(0,40)};
}
// Render data on the site. The external SDK receives neither records nor this transcript.
function localReadAnswer(result,display){
  const rows=Array.isArray(result?.items)?result.items:Array.isArray(result)?result:[result];
  const total=result?.total;
  const lines=[];
  if(Number.isSafeInteger(total))lines.push("Всего записей: "+total+".");
  if(display==="count" && lines.length)return lines[0];
  for(const row of rows){
    if(!row || typeof row!=="object")continue;
    const label=[row.id!=null?"№"+row.id:"",row.name || row.title,row.status_text].filter(Boolean).join(" · ");
    if(label)lines.push(label);
  }
  if(Number.isSafeInteger(total) && rows.length<total)lines.push("В этой выборке: "+rows.length+".");
  return lines.join("\n") || (rows.length?"CRM вернула данные без полей для краткого отображения.":"Записей в выборке нет.");
}
// Two-level catalog: sections of operations first, the operations of a section on request.
const siteDesignTools=new Set(["site_template_examples","browser_preview","page_css_update"]);
function groupKeyOf(name,depth=2){
  if(siteDesignTools.has(name))return "site_design";
  return name.split("_").slice(0,depth).join("_");
}
function catalogGroups(catalog){
  const groups=new Map();
  for(const t of catalog){const k=groupKeyOf(t.name);(groups.get(k)??groups.set(k,[]).get(k)).push(t);}
  // Large sections are split one level deeper so a section stays readable.
  for(const [k,tools] of [...groups]){
    if(tools.length<=20 || k==="site_design")continue;
    groups.delete(k);
    for(const t of tools){const k3=groupKeyOf(t.name,3);(groups.get(k3)??groups.set(k3,[]).get(k3)).push(t);}
  }
  return groups;
}
function groupTitle(key){
  if(key==="site_design")return "оформление и предпросмотр страниц сайта";
  return subjects.find(([prefix])=>key.startsWith(prefix) || prefix.startsWith(key))?.[1] || key;
}
function groupDirectory(groups){
  // Names only: a simple request can go straight to execute; descriptions come with the section.
  return [...groups].map(([key,tools])=>({group:key,title:groupTitle(key),operations:tools.map(t=>t.name)}));
}
const catalogHowTo="catalogGroups — разделы операций этой установки с именами операций. Если операция и её аргументы понятны по имени, сразу верни execute — схему сервис подставит сам, а при ошибке аргументов вернёт её вместе с ошибкой. Описания операций раздела: action=schema, tool=group:<ключ раздела>. Полная схема одной операции: action=schema, tool=<имя>.";
async function run(s,message,{messageId,worker=false,outcome=null}) {
  s.progress.push("Обдумываю поручение","thinking");
  const gateway=s.engine.id==="claude-vclaude";
  let catalog=(await api(s,"/tools/index")).tools;
  if(catalog.some(t=>t.name==="cms_tree_get"))catalog.push(previewTool);
  if(catalog.includes(previewTool) && catalog.some(t=>t.name==="cms_tree_update"))catalog.push(styleTool);
  if(catalog.some(t=>t.name==="cms_tree_list"))catalog.unshift(templatesTool);
  if(gateway)catalog=catalog.filter(t=>t.required_scope?.endsWith(".read") || gatewayTreeTools.has(t.name) || t===styleTool);
  const names=new Map(catalog.map(t=>[t.name,t]));
  const currentUser = !gateway && names.has("cms_ai_current_user_get")
    ? (await api(s,"/tools/cms_ai_current_user_get",{})).data : null;
  // Full catalog with descriptions, as the agent chooses best from it (measured 24.11.2026: a two-level
  // catalog took more steps and more uncached tokens). It is sent once per engine session.
  const groups=catalogGroups(catalog);
  const descriptionLength=Math.max(0,Math.min(120,Math.floor(55000/catalog.length)-40));
  const directory=catalog.map(t=>[t.name,(t.description||"").slice(0,descriptionLength)]);
  // One copy per engine session: a resumed session that already saw this list gets a short note instead.
  const catalogRev=createHash("sha256").update(JSON.stringify(directory)).digest("hex").slice(0,32);
  const catalogKnown=s.engine.stateless!==true && s.engineSession!==null && s.catalogRevs?.[s.engine.id]===catalogRev;
  const site=gateway?{skills:new Map(),globalRules:"",projectRules:""}:await knowledge(await store(s,"settings"));
  const skills=Array.from(site.skills,([name,item])=>({name,description:item.description}));
  const history=[];
  let image;
  const schemas=new Map();
  // Unchanging parts first and the request last: a common start of the message lets the prompt cache cover it.
  const request = {...(catalogKnown?{catalogNote:"Каталог операций прежний, он есть выше в этой сессии."}:{catalog:directory}),globalRules:site.globalRules,projectRules:site.projectRules,skills,
      executionPolicy:"Поручение выполняется в фоне, сотрудник видит ход работы. outcome содержит уже выполненное действие, не повторяй его. Короткий запрос выполни сразу. Когда цель достигнута, верни answer с результатом.",
      protocol:(gateway?"Результат чтения записей показывает сама CMS. Для запроса количества добавь display=count к шагу execute, для списка display=list. Когда схема использует по умолчанию текущего сотрудника или дату, оставь эти аргументы пустыми. ":"")+"Учитывай назначение сущностей: оформление, страница или черновик в контексте сайта относятся к опубликованным материалам и разделам CMS; оказание услуги конкретному клиенту относится к CRM. Для оформления в стиле сайта самостоятельно найди похожие опубликованные страницы, даже если ссылок в переписке нет. Сравни несколько соседних страниц: структуру их контента, повторно используемые шаблоны и фактический вид в браузере. Выбирай шаблон по увиденному, учитывая, пригоден ли он для нового содержимого. Проверь созданный результат в браузере на телефоне и компьютере, приложи снимки. Перед выводом о содержимом страницы проверь ключевые фразы и цену из поручения через expected_text. Если textFound=false, исправь страницу и повтори проверку. Используй уже указанные в переписке страницы как примеры. Когда ответ можно установить осмотром примера, сначала осмотри его доступными инструментами, включая browser_preview для оформления и эффектов. Затем выполни поручение. Вопрос пользователю нужен, только если после проверки остаётся существенный выбор. Если объект запроса вообще не указан и в переписке его нет, уточни объект. Заново запроси схему перед первым вызовом каждого инструмента в этом поручении.",
      today:new Intl.DateTimeFormat("sv-SE",{timeZone:"Europe/Moscow"}).format(new Date()),currentUser,companyId:s.company,attachments:gateway?[]:s.files,
      worker,outcome:gateway && outcome ? {tool:outcome.tool,result:gatewayTreeResult(outcome.result || {})} : outcome,message};
  // Composition of what the service itself puts into the context, in characters (an estimate for the panel).
  const size=v=>JSON.stringify(v??"").length;
  s.contextParts={rules:size(site.globalRules)+size(site.projectRules)+size(request.protocol)+size(request.executionPolicy),
    catalog:catalogKnown?0:size(directory),skills:size(skills),attachments:size(request.attachments),
    conversation:size(message)+size(s.history),tools:0};
  s.contextHistory=history;
  for(let i=0;i<24;i++){
    await s.progress.flush();
    if(s.cancel)throw new Canceled();
    // Messages the employee sent during the run join the work before the next step.
    if(s.notes?.length){
      const notes=s.notes.splice(0);
      history.push({employeeAddedDuringWork:notes,instruction:"Учти эти сообщения сотрудника в текущем поручении."});
    }
    const turn=i===0 ? request : {latestStep:history.at(-1),protocol:"Продолжи текущее поручение с учётом результата."};
    const step=await chatTurn(s,turn,image,request,history);
    image=undefined;
    if(i===0 && !catalogKnown && s.engineSession!==null){
      store(s,"catalogSeen",{engine:s.engine.id,rev:catalogRev}).catch(()=>{});
      (s.catalogRevs??={})[s.engine.id]=catalogRev;
    }
    if(step.action==="background"){history.push({status:"Фоновый режим уже включён. Продолжай выполнение."});continue;}
    if(step.action==="skill"){
      const selected=site.skills.get(step.tool);
      if(selected)s.progress.push("Применяю навык «"+step.tool.replace(/^(skeeks|site)\//,"")+"»","tool");
      history.push(selected?{skill:step.tool,instructions:selected.body}:{error:"Навык недоступен."});
      continue;
    }
    if(step.action==="attachment"){
      if(gateway){history.push({error:"Вложения не подключены к профилю VClaude."});continue;}
      const selected=s.files.find(f=>String(f.id)===step.tool);
      if(!selected){history.push({error:"Файл отсутствует в выбранном чате."});continue;}
      s.progress.push("Читаю файл «"+selected.name+"»","tool");
      const parsed=await extractFile(await store(s,"file",{fileId:selected.id}));
      image=parsed.image;
      history.push({attachment:selected.name,...(image?{imageProvided:true}:parsed)});
      continue;
    }
    if(step.action==="answer")return String(step.answer).slice(0,6000);
    if(step.action==="schema" && typeof step.tool==="string" && step.tool.startsWith("group:")){
      const key=step.tool.slice(6), tools=groups.get(key);
      if(!tools){history.push({error:"Такого раздела нет.",catalogGroups:directory});continue;}
      s.progress.push("Смотрю раздел «"+groupTitle(key)+"»","thinking");
      // A small section comes with full schemas, so the next step can execute directly.
      const withSchemas=tools.length<=6;
      const operations=[];
      for(const t of tools){
        if(withSchemas){
          const schema=[previewTool,styleTool,templatesTool].includes(t)?t:(await api(s,"/tools/"+t.name)).tool;
          schemas.set(t.name,schema);operations.push({schema});
        }else operations.push({name:t.name,description:(t.description||"").slice(0,300)});
      }
      history.push({group:key,title:groupTitle(key),operations,...(withSchemas?{}:{next:"Запроси schema нужной операции."})});
      continue;
    }
    const tool=names.get(step.tool);
    if(!tool){
      history.push({error:"Такой операции нет в каталоге этой сессии. Выбери точное имя из catalog.",catalog:directory,
        siteTools:[templatesTool,previewTool,styleTool].filter(t=>names.has(t.name)).map(t=>t.name),
        treeTools:[...names.keys()].filter(name=>name.startsWith("cms_tree_"))});
      continue;
    }
    if(step.action==="schema"){
      const schema=[previewTool,styleTool,templatesTool].includes(tool)?tool:(await api(s,"/tools/"+tool.name)).tool;
      schemas.set(tool.name,schema);
      history.push({schema});continue;
    }
    if(step.action!=="execute")throw new Error("Неизвестный тип шага.");
    // Execute may come without a separate schema step: the service loads the schema itself;
    // a rejected call returns the schema with the error so the next step can fix the arguments.
    if(!schemas.has(tool.name)){
      schemas.set(tool.name,[previewTool,styleTool,templatesTool].includes(tool)?tool:(await api(s,"/tools/"+tool.name)).tool);
    }
    const args=JSON.parse(step.arguments_json);
    if(!args||Array.isArray(args)||typeof args!=="object")throw new Error("Некорректные параметры.");
    if(tool===templatesTool){
      s.progress.push("Сравниваю шаблоны опубликованных страниц","tool");
      history.push({tool:tool.name,result:await templateExamples(s,args.parent_id)});
      continue;
    }
    if(gateway && tool.name==="cms_tree_list"){
      const {data:page}=await api(s,"/tools/"+tool.name,{...args,cms_site_id:s.siteId,active:"Y"});
      const items=page.items.map(({id,pid,view_file,tree_type_id})=>({id,parent_id:pid,view_file,tree_type_id}));
      history.push({tool:tool.name,args,result:{items,total:page.total,offset:page.offset}});
      continue;
    }
    const localRead=gateway && !gatewayTreeTools.has(tool.name) && tool!==previewTool && tool.required_scope?.endsWith(".read");
    if(gateway && !gatewayTreeTools.has(tool.name) && !localRead && tool!==previewTool && tool!==styleTool){
      return "Эта операция пока недоступна через VClaude на этом стенде.";
    }
    if(tool===previewTool){
      if(!Number.isSafeInteger(args.tree_id) || args.tree_id<1)throw Error("Некорректный номер раздела.");
      const {path,ticket}=await store(s,"previewTree",{treeId:args.tree_id});
      const previewURL=new URL(path,crm);
      if(previewURL.origin!==crm)throw Error("Недопустимый адрес предпросмотра.");
      const response=await fetch(previewURL,{headers:ticket?{"X-Skeeks-Preview":ticket}:{},redirect:"error",signal:AbortSignal.timeout(30000)});
      if(!response.ok)throw Error("Предпросмотр недоступен (HTTP "+response.status+").");
      s.progress.push("Открываю раздел №"+args.tree_id+" в браузере · "+(args.viewport==="phone"?"телефон":"компьютер"),"tool");
      const shot=await capturePreview(args,await response.text(),crm,ticket?undefined:previewURL.href);
      if(gateway && !shot.base64){history.push({tool:tool.name,error:"Не удалось снять выбранное состояние."});continue;}
      if(!shot.base64){history.push({tool:tool.name,args,result:shot.evidence});continue;}
      s.screenshots.set(shot.name,{name:shot.name,base64:shot.base64});
      if(s.screenshots.size>4)throw Error("Слишком много снимков для одного ответа.");
      if(gateway){
        const {layout,width,documentWidth,found}=shot.evidence;
        history.push({tool:tool.name,args,result:{attachment:shot.name,layout,textFound:shot.evidence.textFound,motion:shot.evidence.motion,width,documentWidth,found}});
        continue;
      }
      image=shot.base64;
      history.push({tool:tool.name,args,result:{attachment:shot.name,...shot.evidence,imageProvided:true}});
      continue;
    }
    if(tool.name==="cms_ai_task_create")args.request_key=randomBytes(16).toString("hex");
    if(!tool.required_scope?.endsWith(".read")){
      s.pending={id:random(),tool:tool.name,args,messageId};
      return "Подтвердите действие в CRM.";
    }
    s.progress.push(stepLabel(tool.name),"tool");
    if(s.usage)s.usage.tools++;
    let result;
    try{
      result=(await api(s,"/tools/"+tool.name,args)).data;
    }catch(error){
      if(![400,404,422].includes(error.status))throw error;
      history.push({tool:tool.name,args,error:error.treeMissing?"Раздел не найден.":error.message,schema:schemas.get(tool.name)});
      continue;
    }
    if(localRead){
      if(["count","list"].includes(step.display))return localReadAnswer(result,step.display);
      history.push({tool:tool.name,result:{status:"Данные получены только для показа пользователю. Промежуточное чтение не завершает поручение. Для показа результата используй display=list или count при execute."}});
      continue;
    }
    if(gateway){history.push({tool:tool.name,args,result:gatewayTreeResult(result)});continue;}
    if(tool.name==="cms_ai_project_files_read" && result.kind==="project_image"){
      const parsed=await extractFile(result);
      image=parsed.image;
      history.push({tool:tool.name,args,result:image?{imageProvided:true}:parsed});
    }else{
      history.push({tool:tool.name,args,result:compact(result)});
    }
  }
  s.progress.push("Подвожу итог","thinking");
  await s.progress.flush();
  if(s.notes?.length)history.push({employeeAddedDuringWork:s.notes.splice(0),instruction:"Учти эти сообщения сотрудника в ответе."});
  const final = await chatTurn(s, {
    latestStep: history.at(-1),
    protocol: "Поиск в этом сообщении завершён. Верни только answer. Ответь по подтверждённым результатам. Если сведений мало, обозначь границы поиска и задай конкретный вопрос, который поможет продолжить. Не утверждай, что записи нет, если просмотрена только часть данных."
  }, image, request, history);
  if (final.action === "answer" && typeof final.answer === "string" && final.answer.trim()) {
    return final.answer.trim().slice(0, 6000);
  }
  return "Я остановился на лимите шагов поиска. Продолжить поиск в этом чате?";
}
async function agentWork(req){
  const {chat,run:runId,lease}=await body(req);
  const token=/^Bearer (\S+)$/i.exec(req.headers.authorization || "")?.[1];
  if(!token)throw Error("Отсутствует авторизация сотрудника.");
  const s={chatId:chat,token,ref:{run:runId,lease},cancel:false};
  s.progress=progress(s);
  const ref=s.ref;
  const job=await store(s,"agentStart",ref);
  if(job.done)return job;
  s.model=typeof job.model==="string" ? job.model : null;
  s.effort=typeof job.effort==="string" ? job.effort : null;
  if(job.cancel)return store(s,"agentFinish",{...ref,text:"Поручение остановлено.",canceled:true});
  await loadChat(s,chat);
  if(!s.engine)return store(s,"agentFinish",{...ref,text:"Профиль движка этого чата сейчас недоступен.",failed:true});
  s.pending=null;
  s.screenshots=new Map();
  let outcome=job.state.outcome;
  if(job.confirmed && !outcome){
    const p=job.confirmed;
    const claim=await store(s,"agentClaimWrite",ref);
    if(claim.cancel)return store(s,"agentFinish",{...ref,text:"Поручение остановлено до изменения.",canceled:true});
    if(!claim.claimed)return store(s,"agentFinish",{...ref,text:"Работа прервалась во время изменения. Проверьте результат в CMS перед повтором.",failed:true});
    s.progress.push("Выполняю подтверждённое действие","tool");
    try{
      const headers={"x-skeeks-ai-chat":String(chat),"x-skeeks-ai-request":p.id};
      if(p.tool==="cms_ai_site_drafts_create")headers["x-skeeks-ai-bridge"]=bridgeKey;
      const done=await confirmedTool(s,p,headers);
      outcome={tool:p.tool,result:compact(done)};
      const recordId=Number(done?.id ?? done?.task_id);
      if(Number.isSafeInteger(recordId) && recordId>0)outcome.record={tool:p.tool,id:recordId};
    }catch(error){
      await s.progress.flush();
      return store(s,"agentFinish",{...ref,text:"Не удалось подтвердить результат действия "+p.tool+". "+error.message,failed:true});
    }
    await store(s,"agentWriteResult",{...ref,outcome});
  }
  s.record=outcome?.record;
  let text,state={};
  try{
    text=await run(s,job.message,{messageId:job.messageId,worker:true,outcome});
  }catch(error){
    if(!(error instanceof Canceled))throw error;
    text="Поручение остановлено. Выполненные шаги сохранены в ходе работы.";state={canceled:true};
  }
  await s.progress.flush();
  if(s.usage && s.lastCall){
    if(s.contextParts && s.contextHistory)s.contextParts.tools=JSON.stringify(s.contextHistory).length;
    s.usage.context={lastInput:s.lastCall.input,lastCached:s.lastCall.cached,parts:s.contextParts??{}};
  }
  return store(s,"agentFinish",{...ref,...state,text,pending:state.canceled?null:s.pending,record:s.record,usage:s.usage??null,screenshots:[...s.screenshots.values()]});
}
async function body(req){
  req.setEncoding("utf8");
  let text="";for await(const chunk of req){text+=chunk;if(text.length>5700000)throw Error("Сообщение слишком длинное.");}
  return JSON.parse(text);
}
http.createServer(async(req,res)=>{
  res.setHeader("cache-control","no-store");res.setHeader("x-content-type-options","nosniff");
  const presented = String(req.headers["x-skeeks-ai-bridge"] || "");
  if (!bridgeKey || !timingSafeEqual(createHash("sha256").update(presented).digest(), createHash("sha256").update(bridgeKey).digest())) {
    res.writeHead(403).end();
    return;
  }
  res.setHeader("content-type","application/json; charset=utf-8");
  if(req.url!=="/agent-turn" || req.method!=="POST"){res.writeHead(404).end();return;}
  try{res.end(JSON.stringify(await agentWork(req)));}
  catch(error){res.statusCode=503;res.end(JSON.stringify({error:error.message}));}
}).listen(18764,"127.0.0.1");
