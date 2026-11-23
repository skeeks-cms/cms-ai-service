import {readFile,readdir} from "node:fs/promises";
import {fileURLToPath} from "node:url";
import {spawn} from "node:child_process";
import {join} from "node:path";

const root=process.env.SKEEKS_AI_KNOWLEDGE_DIR || fileURLToPath(new URL("../knowledge/",import.meta.url));
export async function knowledge(project){
  const globalRules=await readFile(join(root,"AGENTS.md"),"utf8");
  const skills=new Map();
  for(const entry of await readdir(join(root,"skills"),{withFileTypes:true})){
    if(!entry.isDirectory())continue;
    const body=await readFile(join(root,"skills",entry.name,"SKILL.md"),"utf8");
    skills.set("skeeks/"+entry.name,{description:/^description:\s*(.+)$/m.exec(body)?.[1] || entry.name,body});
  }
  for(const match of project.skills.matchAll(/^## (.+)\r?\n([\s\S]*?)(?=^## |$(?![\s\S]))/gm)){
    skills.set("site/"+match[1].trim(),{description:match[1].trim(),body:match[2].trim()});
  }
  return {globalRules,projectRules:project.instructions,skills};
}
export function extractFile(file){
  return new Promise((resolve,reject)=>{
    const proc=spawn("python",["-P",fileURLToPath(new URL("./attachment.py",import.meta.url))],{windowsHide:true,stdio:["pipe","pipe","ignore"]});
    let result="";
    const timer=setTimeout(()=>proc.kill(),20000);
    proc.stdout.setEncoding("utf8").on("data",chunk=>{result+=chunk;if(result.length>5000000)proc.kill();});
    proc.on("error",reject);
    proc.on("close",()=>{clearTimeout(timer);try{resolve(JSON.parse(result));}catch{reject(Error("Не удалось обработать вложение."));}});
    proc.stdin.on("error",()=>{});
    proc.stdin.end(JSON.stringify(file));
  });
}
