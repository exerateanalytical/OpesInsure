import puppeteer from "puppeteer-core";
const b=await puppeteer.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',args:['--no-sandbox']});const p=await b.newPage();await p.setViewport({width:390,height:900});
await p.goto("http://localhost:8089/explore",{waitUntil:"networkidle2"});await new Promise(r=>setTimeout(r,2000));
for(let i=0;i<5;i++){const v=await p.evaluate(()=>[...document.querySelectorAll("div")].filter(d=>d.scrollWidth>d.clientWidth+20).map(d=>d.scrollLeft+"/"+d.clientWidth+"/"+d.scrollWidth));console.log(Date.now()%100000,v.join(","));await new Promise(r=>setTimeout(r,4100));}
await b.close();
