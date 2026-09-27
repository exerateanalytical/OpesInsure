import puppeteer from "puppeteer-core";
const b=await puppeteer.launch({executablePath:"C:/Program Files/Google/Chrome/Application/chrome.exe",headless:true});
const p=await b.newPage();await p.setViewport({width:390,height:844});
p.on("console",m=>m.type()==="error"&&console.log("ERR",m.text().slice(0,200)));
await p.goto("http://localhost:8089/sign-in",{waitUntil:"networkidle2",timeout:300000});
await new Promise(r=>setTimeout(r,8000));
console.log((await p.evaluate(()=>document.body.innerText)).slice(0,800));
await p.screenshot({path:process.env.OUT});await b.close();
