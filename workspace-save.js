/* Normal multipart files keep base64 image/PDF bodies out of the host's JSON parser.
 * Authentication, origin checks, tenant scope and permission checks are unchanged. */
window.NSC_SAVE_WORKSPACE = async function (url,payload,headers,baseline={}) {
    const pattern=/^data:(image\/(?:png|jpeg|webp|gif|bmp)|application\/pdf);base64,([A-Za-z0-9+/]*={0,2})$/;
    const existing=new Set(),files=new Map(),ids=new Map();let hasFiles=false;
    const collect=value=>{if(typeof value==='string'&&pattern.test(value))existing.add(value);else if(value&&typeof value==='object')Object.values(value).forEach(collect)};collect(baseline);
    async function convert(value){
        if(typeof value==='string'&&pattern.test(value)){
            hasFiles=true;
            if(!ids.has(value)){
                const hash=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(value));const id=Array.from(new Uint8Array(hash),b=>b.toString(16).padStart(2,'0')).join('');ids.set(value,id);
                if(!existing.has(value)){
                    const match=value.match(pattern);let decoded;try{decoded=atob(match[2])}catch{throw Error('محتوای فایل معتبر نیست؛ معلومات محفوظ است.')}
                    if(decoded.length>10*1024*1024)throw Error('حجم هر فایل حداکثر ۱۰ مگابایت است؛ معلومات محفوظ است.');
                    if(files.size>=20)throw Error('در یک ثبت حداکثر ۲۰ فایل تازه انتخاب کنید؛ معلومات محفوظ است.');
                    files.set(id,new Blob([Uint8Array.from(decoded,c=>c.charCodeAt(0))],{type:match[1]}));
                }
            }
            return {__nsc_file__:ids.get(value)};
        }
        if(Array.isArray(value)){const result=[];for(const child of value)result.push(await convert(child));return result}
        if(value&&typeof value==='object'){const result=Object.create(null);for(const [key,child]of Object.entries(value))result[key]=await convert(child);return result}
        return value;
    }
    const wire=await convert(payload);if(hasFiles)wire.fileTransport=1;
    async function send(formMode){
        const requestHeaders=new Headers(headers);let body;
        if(formMode){requestHeaders.delete('Content-Type');body=new FormData();body.set('payload',JSON.stringify(wire));for(const[id,file]of files)body.append('files['+id+']',file,id+'.bin')}
        else body=JSON.stringify(payload);
        let response;
        try{response=await fetch(url,{method:'POST',headers:requestHeaders,body,cache:'no-store',signal:AbortSignal.timeout(20000)})}
        catch{throw Error('ارتباط با سرور برقرار نشد؛ معلومات این دستگاه محفوظ است.')}
        const raw=await response.text();let result;
        try{result=JSON.parse(raw)}catch{
            const blocked=response.status===403;
            const error=Error(blocked?'هاست درخواست ذخیره را مسدود کرد (HTTP 403). معلومات محفوظ است؛ این خطا باید در گزارش امنیتی هاست بررسی شود.':'پاسخ ذخیرهٔ سرور معتبر نیست. HTTP '+response.status);
            error.status=response.status;error.hostBlocked=blocked;throw error;
        }
        if(!response.ok||!result||result.success!==true){const error=Error(result?.error||result?.message||'ثبت در سرور تأیید نشد.');error.status=response.status;throw error}
        if(!result.data||!Number.isInteger(result.data.stateVersion)||result.data.stateVersion<0){const error=Error('نسخهٔ ثبت‌شده از سرور دریافت نشد؛ معلومات محفوظ است.');error.status=502;throw error}
        return result.data;
    }
    try{return await send(hasFiles)}catch(error){
        // Only a non-JSON host rejection gets one standard form retry; application denials never do.
        if(hasFiles||!error.hostBlocked)throw error;
        return await send(true);
    }
};
