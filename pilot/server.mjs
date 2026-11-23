import {capturePreview,previewTool} from "./browser-worker.mjs";
import {knowledge,extractFile} from "./knowledge.mjs";
import http from "node:http";
import {randomBytes, createHash, timingSafeEqual} from "node:crypto";
import {readFile} from "node:fs/promises";
import {loadEngine, runEngineStep} from "../../skeeks-ai-runner/src/engine.mjs";

const origin = "http://localhost:18764";
const crm = process.env.SKEEKS_AI_SITE_URL || "http://localhost:8080";
const resource = crm + "/cms/rest-api";
const callback = crm + "/ai-panel/callback";
const sessions = new Map();
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
const busyChats = new Set();
const workerProgress = new Map();

let client;
let registering;
const digest = v => createHash("sha256").update(v).digest("base64url");
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
const metadata = await json(crm + "/.well-known/oauth-authorization-server");
async function exchange(fields) {
  return json(metadata.token_endpoint,{method:"POST",body:new URLSearchParams({
    ...fields,client_id:client.client_id,client_secret:client.client_secret
  })});
}
async function api(s,path,args,headers={}) {
  if (Date.now() > s.expires) {
    const tokens = await exchange({grant_type:"refresh_token",refresh_token:s.tokens.refresh_token});
    setTokens(s,tokens);
  }
  const data = await json(resource+path,{headers:{
    ...headers,authorization:"Bearer "+s.tokens.access_token,"content-type":"application/json"
  },...(args===undefined?{}:{method:"POST",body:JSON.stringify(args)})});
  if (!data.success) throw new Error("Операция отклонена CRM.");
  return data;
}
function setTokens(s,tokens) {
  if (!tokens.access_token) throw new Error("Не получен доступ к CRM.");
  s.tokens=tokens; s.expires=Date.now()+Math.max(1,(tokens.expires_in??300)-30)*1000;
}
function compact(data) {
  const value=JSON.stringify(data,(k,v)=>/password|secret|credential|access_token|refresh_token|check_token|api_key|private_key/i.test(k)?"[скрыто]":typeof v==="string"?v.slice(0,1200):Array.isArray(v)?v.slice(0,12):v);
  return value.length>7200?value.slice(0,7200)+"…":JSON.parse(value);
}
async function store(s,op,fields={}) {
  if(Date.now()>s.expires)setTokens(s,await exchange({grant_type:"refresh_token",refresh_token:s.tokens.refresh_token}));
  return json(crm+"/ai-chat-store/index",{method:"POST",headers:{
    authorization:"Bearer "+s.tokens.access_token,"content-type":"application/json","x-skeeks-ai-bridge":bridgeKey
  },body:JSON.stringify({op,id:s.chatId,...fields})});
}
async function chatTurn(s, turn, image, request = turn, steps = []) {
  const binding = createHash("sha256").update(resource + "|" + s.chatId).digest("hex");
  const result = await runEngineStep(s.engine, {
    binding, sessionId: s.engineSession, turn, image, history: s.history, request, steps
  });
  if (s.engineSession === null && result.sessionId !== null) {
    await store(s, "bindEngine", {engine: s.engine.id, session: result.sessionId});
    s.engineSession = result.sessionId;
  }
  return result.step;
}
async function loadChat(s,id){
  const chat=await store(s,"get",{id});
  s.siteId=Number(chat.site_id);
  s.engineId=chat.engine;
  s.engine=enginesFor(chat.owner_id).find(engine=>engine.id===chat.engine);
  s.draftJob=chat.draft_job;s.chatId=Number(chat.id);s.engineSession=chat.engine_sessions[chat.engine] ?? null;s.history=chat.history;s.files=chat.files;
  s.company=Number(chat.company_id)||null;s.pending=chat.pending;
}
async function snapshot(s){
  const {chats,owner_id}=await store(s,"list");
  const active=workerProgress.get(s.chatId);
  return {stage:active?.id===Number(s.draftJob?.id)?active?.events.at(-1):null,engine:s.engineId ?? defaultEngine.id,engines:enginesFor(owner_id).map(e=>e.id),chats,draftJob:s.draftJob,files:s.files||[],chatId:s.chatId,history:s.history,company:s.company,pending:s.pending};
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
async function run(s,message,{messageId,worker=false,outcome=null}) {
  s.progress.push("Проверяю доступные операции на сайте.");
  const gateway=s.engine.id==="claude-vclaude";
  let catalog=(await api(s,"/tools/index")).tools;
  if(catalog.some(t=>t.name==="cms_tree_get"))catalog.push(previewTool);
  if(catalog.includes(previewTool) && catalog.some(t=>t.name==="cms_tree_update"))catalog.push(styleTool);
  if(catalog.some(t=>t.name==="cms_tree_list"))catalog.unshift(templatesTool);
  if(gateway)catalog=catalog.filter(t=>t.required_scope?.endsWith(".read") || gatewayTreeTools.has(t.name) || t===styleTool);
  const names=new Map(catalog.map(t=>[t.name,t]));
  const currentUser = !gateway && names.has("cms_ai_current_user_get")
    ? (await api(s,"/tools/cms_ai_current_user_get",{})).data : null;
  // Keep room for the conversation, full schemas and tool results.
  const descriptionLength=Math.max(0,Math.min(120,Math.floor(55000/catalog.length)-40));
  const directory=catalog.map(t=>[t.name,(t.description||"").slice(0,descriptionLength)]);
  const site=gateway?{skills:new Map(),globalRules:"",projectRules:""}:await knowledge(await store(s,"settings"));
  const skills=Array.from(site.skills,([name,item])=>({name,description:item.description}));
  const history=[];
  let image;
  const schemas=new Map();
  const request = {message,today:new Intl.DateTimeFormat("sv-SE",{timeZone:"Europe/Moscow"}).format(new Date()),currentUser,companyId:s.company,catalog:directory,globalRules:site.globalRules,projectRules:site.projectRules,skills,attachments:gateway?[]:s.files,
      worker,outcome:gateway && outcome ? {tool:outcome.tool,result:gatewayTreeResult(outcome.result || {})} : outcome,
      executionPolicy:worker
        ? "Поручение уже выполняется в фоне. Продолжи его. outcome содержит уже выполненное действие, не повторяй его. Когда цель достигнута, верни answer с результатом."
        : "Оцени объём работы. Если нужны подготовка материалов, несколько зависимых изменений или длительное исследование, выбери action=background сразу. Короткий запрос выполни здесь.",
      protocol:(gateway?"Результат чтения записей показывает сама CMS. Для запроса количества добавь display=count к шагу execute, для списка display=list. Когда схема использует по умолчанию текущего сотрудника или дату, оставь эти аргументы пустыми. ":"")+"Учитывай назначение сущностей: оформление, страница или черновик в контексте сайта относятся к опубликованным материалам и разделам CMS; оказание услуги конкретному клиенту относится к CRM. Для оформления в стиле сайта самостоятельно найди похожие опубликованные страницы, даже если ссылок в переписке нет. Сравни несколько соседних страниц: структуру их контента, повторно используемые шаблоны и фактический вид в браузере. Выбирай шаблон по увиденному, учитывая, пригоден ли он для нового содержимого. Проверь созданный результат в браузере на телефоне и компьютере, приложи снимки. Перед выводом о содержимом страницы проверь ключевые фразы и цену из поручения через expected_text. Если textFound=false, исправь страницу и повтори проверку. Используй уже указанные в переписке страницы как примеры. Когда ответ можно установить осмотром примера, сначала осмотри его доступными инструментами, включая browser_preview для оформления и эффектов. Затем выполни поручение. Вопрос пользователю нужен, только если после проверки остаётся существенный выбор. Если объект запроса вообще не указан и в переписке его нет, уточни объект. Заново запроси схему перед первым вызовом каждого инструмента в этом поручении."};
  for(let i=0;i<(worker?24:5);i++){
    if (!worker && i===4) {
      await store(s,"queueAgent",{messageId});
      return null;
    }
    const turn=i===0 ? request : {latestStep:history.at(-1),protocol:"Продолжи текущее поручение с учётом результата."};
    const step=await chatTurn(s,turn,image,request,history);
    image=undefined;
    if(step.action==="background"){
      if(worker){history.push({status:"Фоновый режим уже включён. Продолжай выполнение."});continue;}
      await store(s,"queueAgent",{messageId});
      return null;
    }
    if(step.action==="skill"){
      const selected=site.skills.get(step.tool);
      history.push(selected?{skill:step.tool,instructions:selected.body}:{error:"Навык недоступен."});
      continue;
    }
    if(step.action==="attachment"){
      if(gateway){history.push({error:"Вложения не подключены к профилю VClaude."});continue;}
      const selected=s.files.find(f=>String(f.id)===step.tool);
      if(!selected){history.push({error:"Файл отсутствует в выбранном чате."});continue;}
      s.progress.push("Читаю вложение "+selected.name+".");
      const parsed=await extractFile(await store(s,"file",{fileId:selected.id}));
      image=parsed.image;
      history.push({attachment:selected.name,...(image?{imageProvided:true}:parsed)});
      continue;
    }
    if(step.action==="answer")return String(step.answer).slice(0,6000);
    const tool=names.get(step.tool);
    if(!tool){
      history.push({error:"Такой операции нет в каталоге этой сессии. Выбери точное имя из catalog. Для создания страницы ориентируйся на инструменты оформления сайта.",
        siteTools:[templatesTool,previewTool,styleTool].filter(t=>names.has(t.name)).map(t=>t.name),
        treeTools:[...names.keys()].filter(name=>name.startsWith("cms_tree_"))});
      continue;
    }
    if(step.action==="schema"){
      s.progress.push("Изучаю параметры " + tool.name + ".");
      const schema=[previewTool,styleTool,templatesTool].includes(tool)?tool:(await api(s,"/tools/"+tool.name)).tool;
      schemas.set(tool.name,schema);
      history.push({schema});continue;
    }
    if(step.action!=="execute")throw new Error("Неизвестный тип шага.");
    if(!schemas.has(tool.name)){history.push({tool:tool.name,error:"Запроси schema в текущем запуске перед execute."});continue;}
    const args=JSON.parse(step.arguments_json);
    if(!args||Array.isArray(args)||typeof args!=="object")throw new Error("Некорректные параметры.");
    if(tool===templatesTool){
      s.progress.push("Сравниваю шаблоны опубликованных страниц.");
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
      if(!worker){await store(s,"queueAgent",{messageId});return null;}
      if(!Number.isSafeInteger(args.tree_id) || args.tree_id<1)throw Error("Некорректный номер раздела.");
      const {path,ticket}=await store(s,"previewTree",{treeId:args.tree_id});
      const previewURL=new URL(path,crm);
      if(previewURL.origin!==crm)throw Error("Недопустимый адрес предпросмотра.");
      const response=await fetch(previewURL,{headers:ticket?{"X-Skeeks-Preview":ticket}:{},redirect:"error",signal:AbortSignal.timeout(30000)});
      if(!response.ok)throw Error("Предпросмотр недоступен (HTTP "+response.status+").");
      s.progress.push("Открываю раздел №"+args.tree_id+" в браузере · "+(args.viewport==="phone"?"телефон":"компьютер"));
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
      s.pending={id:random(),tool:tool.name,args,...(worker?{messageId}:{})};
      return "Подтвердите действие в CRM.";
    }
    s.progress.push("Запрашиваю " + tool.name + ".");
    let result;
    try{
      result=(await api(s,"/tools/"+tool.name,args)).data;
    }catch(error){
      if(![404,422].includes(error.status))throw error;
      history.push({tool:tool.name,args,error:error.treeMissing?"Раздел не найден.":error.message});
      continue;
    }
    s.progress.push("CRM вернула результат.");
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
  s.progress.push("Подвожу итог по полученным данным.");
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
  const s={chatId:chat,tokens:{access_token:token},expires:Infinity,progress:[]};
  const ref={run:runId,lease};
  const job=await store(s,"agentStart",ref);
  if(job.done)return job;
  await loadChat(s,chat);
  if(!s.engine)throw Error("Профиль движка этого чата сейчас недоступен.");
  s.pending=null;
  s.screenshots=new Map();
  let outcome=job.state.outcome;
  if(job.confirmed && !outcome){
    const p=job.confirmed;
    const claim=await store(s,"agentClaimWrite",ref);
    if(!claim.claimed)return store(s,"agentFinish",{...ref,text:"Работа прервалась во время изменения. Проверьте результат в CMS перед повтором."});
    try{
      const headers={"x-skeeks-ai-chat":String(chat),"x-skeeks-ai-request":p.id};
      if(p.tool==="cms_ai_site_drafts_create")headers["x-skeeks-ai-bridge"]=bridgeKey;
      outcome={tool:p.tool,result:compact(await confirmedTool(s,p,headers))};
    }catch(error){
      return store(s,"agentFinish",{...ref,text:"Не удалось подтвердить результат действия "+p.tool+". "+error.message});
    }
    await store(s,"agentWriteResult",{...ref,outcome});
  }
  const active={id:Number(runId),events:s.progress};
  workerProgress.set(s.chatId,active);
  try{
    const text=await run(s,job.message,{messageId:job.messageId,worker:true,outcome});
    return await store(s,"agentFinish",{...ref,text,pending:s.pending,screenshots:[...s.screenshots.values()]});
  }finally{if(workerProgress.get(s.chatId)===active)workerProgress.delete(s.chatId);}
}
async function body(req){
  req.setEncoding("utf8");
  let text="";for await(const chunk of req){text+=chunk;if(text.length>5700000)throw Error("Сообщение слишком длинное.");}
  return JSON.parse(text);
}
setInterval(()=>{for(const [id,s] of sessions)if(s.last<Date.now()-3600000 && !s.busy)sessions.delete(id);},60000).unref();
http.createServer(async(req,res)=>{
  res.setHeader("cache-control","no-store");res.setHeader("x-content-type-options","nosniff");
  const presented = String(req.headers["x-skeeks-ai-bridge"] || "");
  if (!bridgeKey || !timingSafeEqual(createHash("sha256").update(presented).digest(), createHash("sha256").update(bridgeKey).digest())) {
    res.writeHead(403).end();
    return;
  }
  const url=new URL(req.url,origin);
  const panel=/^\/panel\/([a-f0-9]{32})(?=\/)/.exec(url.pathname);
  const tab=panel?.[1] || (url.pathname==="/callback" && /^([a-f0-9]{32})\./.exec(url.searchParams.get("state")||"")?.[1]) || "";
  const prefix=tab?"/panel/"+tab:"";
  if(panel)url.pathname=url.pathname.slice(prefix.length);
  const cookieName="skeeks_ai_pilot"+(tab?"_"+tab:"");
  const id=(req.headers.cookie||"").split("; ").find(v=>v.startsWith(cookieName+"="))?.slice(cookieName.length+1);
  let s=sessions.get(id);
  const redirect=to=>res.writeHead(302,{location:to.startsWith("/")?prefix+to:to}).end();
  const reply=value=>{res.setHeader("content-type","application/json; charset=utf-8");res.end(JSON.stringify(value));};
  try{
    if(req.url==="/agent-turn" && req.method==="POST"){
      try{return reply(await agentWork(req));}
      catch(error){res.statusCode=503;return reply({error:error.message});}
    }
    if(url.pathname==="/start"){
      if(s)sessions.delete(id);
      const sid=random(), state=(tab?tab+".":"")+random(),verifier=random();
      sessions.set(sid,{last:Date.now(),state,verifier,company:Number(url.searchParams.get("company"))||null,history:[],progress:[]});
      res.setHeader("set-cookie",cookieName+"="+sid+"; Path=/; Max-Age=3600; HttpOnly; SameSite=Lax");
      registering??=json(metadata.registration_endpoint,{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({client_name:"SkeekS AI (локальный стенд)",redirect_uris:[callback]})});
      try { client=await registering; } catch(error) { registering=undefined; throw error; }
      const auth=new URL(metadata.authorization_endpoint);
      auth.search=new URLSearchParams({response_type:"code",client_id:client.client_id,redirect_uri:callback,resource,state,code_challenge_method:"S256",code_challenge:digest(verifier)});
      return redirect(auth.href);
    }
    if(url.pathname==="/callback"){
      if(!s?.state||s.state!==url.searchParams.get("state"))throw Error("Не удалось проверить OAuth-вход.");
      delete s.state;
      setTokens(s,await exchange({grant_type:"authorization_code",code:url.searchParams.get("code"),redirect_uri:callback,resource,code_verifier:s.verifier}));
      delete s.verifier;s.cardCompany=s.company;
      const {chats}=await store(s,"list");
      const start=chats.find(c=>(Number(c.company_id)||null)===s.company);
      if(start)await loadChat(s,start.id);
      return redirect("/");
    }
    if(!s?.tokens)return redirect("/start");
    s.last=Date.now();
    if(url.pathname==="/settings" && req.method==="GET"){
      const project=await store(s,"settings");
      const info=await knowledge(project);
      return reply({...project,globalRules:info.globalRules,globalSkills:[...info.skills.keys()].filter(k=>k.startsWith("skeeks/"))});
    }
    if(url.pathname==="/file" && req.method==="GET"){
      const f=await store(s,"file",{id:Number(url.searchParams.get("chat")),fileId:Number(url.searchParams.get("id"))});
      res.setHeader("content-type","application/octet-stream");
      res.setHeader("content-disposition","attachment; filename*=UTF-8''"+encodeURIComponent(f.name).replace(/'/g,"%27"));
      return res.end(Buffer.from(f.base64,"base64"));
    }
    if(url.pathname==="/progress" && req.method==="GET")return reply({events:s.progress});
    if(url.pathname==="/history" && req.method==="GET"){
      if(s.chatId && !s.busy)await loadChat(s,s.chatId);
      return reply(await snapshot(s));
    }
    if(req.method!=="POST"||req.headers["content-type"]!=="application/json"){res.writeHead(403).end();return;}
    if(s.busy){res.writeHead(409).end();return;}
    s.busy=true;
    s.progress=[];
    let locked;
    try{
      const data=await body(req);
      if(url.pathname==="/settings"){
        return reply(await store(s,"saveSettings",{instructions:data.instructions,skills:data.skills,revision:data.revision}));
      }
      if(url.pathname==="/new" || url.pathname==="/select"){
        if(url.pathname==="/new"){
          const {owner_id}=await store(s,"list");
          const engine=data.engine ?? defaultEngine.id;
          if(!enginesFor(owner_id).some(e=>e.id===engine))throw Error("Движок недоступен для этой учётной записи.");
          const created=await store(s,"new",{company:s.cardCompany,engine});
          await loadChat(s,created.id);
        }else await loadChat(s,data.id);
        return reply(await snapshot(s));
      }
      if(!s.chatId && url.pathname==="/message"){
        const created=await store(s,"new",{company:s.cardCompany});
        await loadChat(s,created.id);
      }
      if(!s.chatId || (data.chatId!=null && Number(data.chatId)!==s.chatId))throw Error("Обновите выбранный чат.");
      if(busyChats.has(s.chatId))throw Error("В этом чате уже выполняется поручение.");
      locked=s.chatId;busyChats.add(locked);
      await loadChat(s,locked);
      if(!s.engine)throw Error("Профиль движка этого чата сейчас недоступен.");
      let answer;
      if(url.pathname==="/confirm"){
        const p=s.pending;
        if(!p||p.id!==data.id)throw Error("Действие уже обработано.");
        if(p.messageId){
          await store(s,"queueAgent",{messageId:p.messageId,confirmation:p.id});
          await loadChat(s,locked);
          return reply(await snapshot(s));
        }
        const draftJob=p.tool==="cms_ai_site_drafts_create";
        if(!draftJob)await store(s,"consume",{confirmation:p.id});
        s.progress.push("Выполняю подтверждённое действие " + p.tool + ".");
        const headers=draftJob?{"x-skeeks-ai-bridge":bridgeKey,"x-skeeks-ai-chat":String(s.chatId),"x-skeeks-ai-request":p.id}:{"x-skeeks-ai-chat":String(s.chatId)};
        const result=await confirmedTool(s,p,headers);
        if(draftJob)await store(s,"consume",{confirmation:p.id});
        delete s.pending;
        if(draftJob){
          await store(s,"append",{role:"assistant",text:"Задание №"+result.run_id+" принято. Ссылки появятся в этом чате."});
          await loadChat(s,locked);
          return reply(await snapshot(s));
        }
        s.progress.push("CRM вернула результат. Готовлю ответ.");
        const display = s.engine.id==="claude-vclaude" ? gatewayTreeResult(result) : compact(result);
        const fallback = result.message || [result.name || result.title, result.id && ("№" + result.id), result.url].filter(Boolean).join(" · ") || JSON.stringify(display);
        try {
          const summary = await chatTurn(s,{message:"Сообщи результат выполненного REST-вызова кратко по-русски. Назови номер и состояние, если они известны. Если CRM требует дополнительного действия, объясни его. Используй answer.", catalog:[],steps:[{tool:p.tool,result:display}]});
          answer = summary.action === "answer" && summary.answer ? String(summary.answer) : fallback;
        } catch { answer = fallback; }
        if (result.url?.startsWith(crm+"/") && !answer.includes(result.url)) answer += "\n"+result.url;
      }else if(url.pathname==="/message"){
        if(s.draftJob?.job_type==="cms-ai.agent-turn" && !Number(s.draftJob.done) &&
          ["queued","running","retry_wait"].includes(s.draftJob.status))throw Error("В этом чате выполняется поручение.");
        delete s.pending;
        const files=data.files||[];
        if(!Array.isArray(files)||files.length>4)throw Error("Можно прикрепить до четырёх файлов.");
        const message=String(data.message??"").trim() || (files.length?"Изучи приложенные файлы.":"");
        if(!message||message.length>1800)throw Error("Введите поручение до 1800 символов.");
        await store(s,"append",{role:"user",text:message,files});
        await loadChat(s,locked);
        answer=await run(s,message,{messageId:Number(s.history.at(-1).id)});
        if(answer===null){
          await loadChat(s,locked);
          return reply(await snapshot(s));
        }
      }else {res.writeHead(404).end();return;}
      await store(s,"append",{role:"assistant",text:answer,pending:s.pending});
      s.history.push({role:"assistant",text:answer});
      reply(await snapshot(s));
    }finally{if(locked)busyChats.delete(locked);s.busy=false;}
  }catch(error){res.statusCode=400;reply({error:error.message});}
}).listen(18764,"127.0.0.1");
