import {createRequire} from "node:module";

export const previewTool = {
  name:"browser_preview", required_scope:"cms.tree.read",
  description:"Открыть опубликованный раздел сайта или черновик в браузере, измерить оформление, анимации и переходы и приложить снимок телефона или компьютера к ответу.",
  inputSchema:{type:"object",additionalProperties:false,required:["tree_id","viewport"],properties:{
    tree_id:{type:"integer",minimum:1},viewport:{enum:["phone","desktop"]},
    expected_text:{type:"string",maxLength:160,description:"Фраза из поручения, присутствие которой требуется проверить в видимом тексте страницы или выбранного элемента."},
    click_button:{type:"string",description:"Доступное имя кнопки для одного нажатия. Возьми его из controls предыдущего предпросмотра."},
    selector:{type:"string",description:"CSS-селектор проверяемого элемента. При наличии он будет виден на снимке."}
  }}
};

const viewports={phone:{width:390,height:844},desktop:{width:1440,height:900}};
export async function capturePreview({tree_id,viewport,selector,click_button,expected_text},html,origin,pageURL){
  if(!Number.isSafeInteger(tree_id)||tree_id<1 || !Object.hasOwn(viewports,viewport) ||
    (expected_text!==undefined && (typeof expected_text!=="string" || !expected_text.trim() || expected_text.length>160)) ||
    (click_button!==undefined && (typeof click_button!=="string" || !click_button.trim() || click_button.length>180)) ||
    (selector!==undefined && (typeof selector!=="string" || selector.length>256)))throw Error("Проверьте параметры предпросмотра.");
  const {chromium}=createRequire(import.meta.url)(process.env.SKEEKS_AI_PLAYWRIGHT || "playwright");
  const browser=await chromium.launch({channel:process.env.SKEEKS_AI_BROWSER_CHANNEL || undefined,chromiumSandbox:true});
  try{
    const context=await browser.newContext({viewport:viewports[viewport],javaScriptEnabled:true,reducedMotion:"reduce",
      serviceWorkers:"block",acceptDownloads:false});
    const previewURL=pageURL || new URL("/__skeeks_preview__/"+tree_id,origin).href;
    const imageResponses=new Map();
    let served=false,failedAssets=0;
    await context.route("**/*",async route=>{
      const req=route.request(),url=new URL(req.url());
      if(!served && url.href===previewURL && req.resourceType()==="document"){
        served=true;
        return route.fulfill({contentType:"text/html; charset=utf-8",body:html,headers:{
          "content-security-policy":"default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'none'; worker-src 'none'",
          "referrer-policy":"no-referrer"
        }});
      }
      const siteScript=req.resourceType()==="script" && /^\/assets\/[a-zA-Z0-9_./-]+\.js$/.test(url.pathname);
      if(url.origin!==origin || req.method()!=="GET" || (!siteScript && (!["stylesheet","font","image"].includes(req.resourceType()) ||
        !/\.(css|woff2?|ttf|otf|png|jpe?g|webp|gif|ico|svg|avif)$/i.test(url.pathname))))return route.abort();
      try{
        const response=await fetch(url,{redirect:"error",signal:AbortSignal.timeout(8000)});
        if(req.resourceType()==="image")imageResponses.set(url.origin+url.pathname,response.status);
        if(!response.ok){failedAssets++;return await route.abort();}
        return await route.fulfill({body:Buffer.from(await response.arrayBuffer()),contentType:response.headers.get("content-type") || "application/octet-stream"});
      }catch{failedAssets++;return route.abort().catch(()=>{});}
    });
    const page=await context.newPage();
    await page.goto(previewURL,{waitUntil:"load",timeout:45000});
    const controls=await page.getByRole("button").evaluateAll(buttons=>buttons
      .filter(b=>b.getBoundingClientRect().width && b.getBoundingClientRect().height)
      .map(b=>b.getAttribute("aria-label") || b.innerText?.trim()).filter(Boolean).slice(0,35));
    let interaction;
    if(click_button){
      const button=page.getByRole("button",{name:click_button,exact:true});
      if(await button.count()!==1)return {evidence:{controls,error:"Не найдена единственная кнопка с указанным именем."}};
      const targetId=await button.getAttribute("aria-controls");
      const before=await button.getAttribute("aria-expanded");
      await button.click({timeout:5000});
      if(before==="false" && targetId){
        await page.waitForFunction(id=>{
          const target=document.getElementById(id);
          return target && target.getBoundingClientRect().height>0 &&
            getComputedStyle(target).visibility!=="hidden" &&
            Array.from(document.querySelectorAll("[aria-controls]")).some(b=>b.getAttribute("aria-controls")===id && b.getAttribute("aria-expanded")==="true");
        },targetId,{timeout:3500}).catch(()=>{});
      }
      interaction=await page.evaluate(({targetId,before})=>{
        const target=document.getElementById(targetId);
        const toggle=Array.from(document.querySelectorAll("[aria-controls]")).find(b=>b.getAttribute("aria-controls")===targetId);
        return {before,after:toggle?.getAttribute("aria-expanded"),visibleLinks:target ?
          Array.from(target.querySelectorAll("a")).filter(a=>a.checkVisibility() && a.getBoundingClientRect().bottom>0 &&
            a.getBoundingClientRect().top<innerHeight).map(a=>a.innerText.trim()).filter(Boolean).slice(0,12):[]};
      },{targetId,before});
    }
    await page.emulateMedia({reducedMotion:"no-preference"});
    const evidence=await page.evaluate(async ({selector,clicked,expected_text})=>{
      await document.fonts.ready;
      const el=selector ? document.querySelector(selector) : document.querySelector("main") || document.body;
      if(el && selector && !clicked)el.scrollIntoView({block:"center"});
      const images=Array.from(document.images).filter(img=>{
        const rect=img.getBoundingClientRect();
        return rect.width && rect.height && rect.top<innerHeight && rect.bottom>0 && img.checkVisibility();
      }).slice(0,16).map(img=>{
        const src=new URL(img.currentSrc || img.src,location.href);
        return {alt:img.alt.slice(0,100),src:src.protocol==="data:"?"data:":src.origin+src.pathname,
          width:img.naturalWidth,height:img.naturalHeight,complete:img.complete};
      });
      const layout={};
      for(const key of ["h1",".hero",".wrap",".grid-3",".card",".tariff"]){
        const target=document.querySelector(key);
        if(!target)continue;
        const style=getComputedStyle(target);
        layout[key]={width:Math.round(target.getBoundingClientRect().width),fontSize:style.fontSize,display:style.display,
          columns:style.gridTemplateColumns,padding:style.padding,background:style.backgroundColor,color:style.color};
      }
      const motion=[];
      const seen=new Set();
      const section=document.querySelector("h1")?.closest("section,main") || document.body;
      for(const node of [section,...section.querySelectorAll("*")]){
        if(motion.length>=14)break;
        const style=getComputedStyle(node);
        if(style.animationName==="none" && !style.transitionDuration.split(",").some(v=>parseFloat(v)>0))continue;
        const key=[style.animationName,style.animationDuration,style.transitionProperty,style.transitionDuration].join(" ");
        if(seen.has(key))continue;
        seen.add(key);
        motion.push({element:node.localName,animation:style.animationName,duration:style.animationDuration,
          transition:style.transitionProperty,transitionDuration:style.transitionDuration});
      }
      const normalize=t=>t.replace(/\s+/gu," ").trim().toLocaleLowerCase("ru");
      const textFound=expected_text===undefined ? undefined : !!el && el.checkVisibility() && normalize(el.innerText).includes(normalize(expected_text));
      return {layout,motion,images,textFound,found:!!el,text:el?.innerText?.slice(0,500),color:el && getComputedStyle(el).color,
        width:innerWidth,documentWidth:document.documentElement.scrollWidth};
    },{selector,clicked:!!click_button,expected_text});
    for(const img of evidence.images)img.httpStatus=imageResponses.get(img.src) ?? null;
    await page.emulateMedia({reducedMotion:"reduce"});
    const bytes=await page.screenshot({type:"jpeg",quality:80,timeout:20000});
    return {name:"preview-"+tree_id+"-"+viewport+".jpg",base64:bytes.toString("base64"),evidence:{...evidence,controls,interaction,failedAssets}};
  }finally{await browser.close();}
}
