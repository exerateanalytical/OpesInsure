/**
 * Pure helpers for the in-app document viewer (app/documents/view.tsx).
 * No React Native imports so node tests can load it.
 */

/** pdf.js (UMD build, served from cdnjs) renders every page at the WebView's width. */
export const PDFJS_VERSION = "3.11.174";
export const PDFJS_BASE = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${PDFJS_VERSION}`;

export type ViewerSource =
  | { kind: "url"; url: string }
  | { kind: "quote"; quoteId: string };

/** Route params for /documents/view. `source` is url:<https…> or quote:<id>. */
export function viewerParams(source: ViewerSource, title: string, fileName?: string) {
  return {
    source: source.kind === "url" ? `url:${source.url}` : `quote:${source.quoteId}`,
    title,
    ...(fileName ? { fileName } : {}),
  };
}

export function parseViewerSource(raw: string | undefined | null): ViewerSource | null {
  const v = String(raw ?? "");
  if (v.startsWith("url:")) {
    const url = v.slice(4);
    return /^https?:\/\//i.test(url) ? { kind: "url", url } : null;
  }
  if (v.startsWith("quote:") && v.length > 6) return { kind: "quote", quoteId: v.slice(6) };
  return null;
}

/** "Policy schedule POL-2026-000002" -> "Policy-schedule-POL-2026-000002.pdf" */
export function safeFileName(title: string | undefined | null, fallback = "document"): string {
  const base = String(title ?? "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^A-Za-z0-9._-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 80);
  const name = base || fallback;
  return /\.pdf$/i.test(name) ? name : `${name}.pdf`;
}

/** True when the URL is served by our API and must carry the bearer token. */
export function needsBearer(url: string, apiBaseUrl: string): boolean {
  if (!apiBaseUrl) return false;
  const base = apiBaseUrl.replace(/\/+$/, "");
  return url.startsWith(`${base}/`) || url === base;
}

/** API-relative path for an absolute URL under the API base (for api({ raw })). */
export function apiPathFor(url: string, apiBaseUrl: string): string {
  return url.slice(apiBaseUrl.replace(/\/+$/, "").length);
}

/** "1.2 MB" style size for the header. */
export function formatBytes(bytes: number | null | undefined): string | null {
  if (!bytes || bytes <= 0) return null;
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Self-contained HTML that renders a base64 PDF with pdf.js, every page
 * stacked and scaled to the viewport width (device-pixel-ratio aware so text
 * stays crisp), pinch-zoom enabled. Posts {type:"pages"|"error"} to RN.
 */
export function viewerHtml(base64: string, options: { background?: string; accent?: string } = {}): string {
  const bg = options.background ?? "#FAF7F2";
  const accent = options.accent ?? "#35419F";
  return `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=4, user-scalable=yes">
<style>
  html,body{margin:0;padding:0;background:${bg};}
  #pages{padding:8px;display:flex;flex-direction:column;gap:10px;align-items:center;}
  canvas{display:block;width:100%;height:auto;background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(15,21,53,.12);}
  .page{width:100%;max-width:900px;}
  #status{font:14px/20px -apple-system,Roboto,Inter,sans-serif;color:#57526A;text-align:center;padding:24px 16px;}
  #bar{position:fixed;left:0;top:0;height:3px;width:0;background:${accent};transition:width .2s;}
</style></head><body>
<div id="bar"></div><div id="status">Loading…</div><div id="pages"></div>
<script src="${PDFJS_BASE}/pdf.min.js"></script>
<script>
(function(){
  var post=function(m){try{window.ReactNativeWebView&&window.ReactNativeWebView.postMessage(JSON.stringify(m));}catch(e){}};
  try{
    var b64=${JSON.stringify(base64)};
    var bin=atob(b64),len=bin.length,bytes=new Uint8Array(len);
    for(var i=0;i<len;i++)bytes[i]=bin.charCodeAt(i);
    pdfjsLib.GlobalWorkerOptions.workerSrc="${PDFJS_BASE}/pdf.worker.min.js";
    pdfjsLib.getDocument({data:bytes}).promise.then(function(pdf){
      var pages=document.getElementById("pages"),status=document.getElementById("status"),bar=document.getElementById("bar");
      var width=Math.min(pages.clientWidth-16,900),dpr=Math.min(window.devicePixelRatio||1,3);
      var n=pdf.numPages,done=0;
      post({type:"pages",count:n});
      var render=function(i){
        return pdf.getPage(i).then(function(page){
          var vp=page.getViewport({scale:1});var scale=width/vp.width;var v=page.getViewport({scale:scale*dpr});
          var wrap=document.createElement("div");wrap.className="page";
          var c=document.createElement("canvas");c.width=v.width;c.height=v.height;wrap.appendChild(c);pages.appendChild(wrap);
          return page.render({canvasContext:c.getContext("2d"),viewport:v}).promise.then(function(){
            done++;bar.style.width=Math.round(done/n*100)+"%";if(done===n){status.remove();setTimeout(function(){bar.remove();},300);}
          });
        });
      };
      var chain=Promise.resolve();
      for(var p=1;p<=n;p++){(function(i){chain=chain.then(function(){return render(i);});})(p);}
      chain.catch(function(e){post({type:"error",message:String(e&&e.message||e)});});
    }).catch(function(e){post({type:"error",message:String(e&&e.message||e)});});
  }catch(e){post({type:"error",message:String(e&&e.message||e)});}
})();
</script></body></html>`;
}
