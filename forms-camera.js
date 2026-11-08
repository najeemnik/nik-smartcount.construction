/* Shared live camera. All images continue through the existing attachment validation pipeline. */
(()=>{'use strict';let current;
const tx=(en,fa,ps=fa)=>document.documentElement.lang==='en'?en:document.documentElement.lang==='ps'?ps:fa;
window.NSC_CLOSE_CAMERA=()=>current?.close();
window.NSC_CAMERA_CAPTURE=()=>{
 if(current)return Promise.resolve(null);
 return new Promise(resolve=>{
  const previous=document.activeElement,overlay=document.createElement('div');overlay.className='nsc-camera-overlay';overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');overlay.setAttribute('aria-label',tx('Camera','کمره'));
  const panel=document.createElement('section');panel.className='nsc-camera-panel';
  const header=document.createElement('header'),title=document.createElement('h2');title.textContent=tx('Camera','کمره');const cancel=document.createElement('button');cancel.type='button';cancel.textContent=tx('Close','بستن');header.append(title,cancel);
  const video=document.createElement('video');video.autoplay=true;video.muted=true;video.playsInline=true;video.setAttribute('playsinline','');
  const message=document.createElement('p');message.setAttribute('role','status');message.textContent=tx('Starting camera…','کمره در حال آماده‌شدن است…');
  const actions=document.createElement('footer'),capture=document.createElement('button');capture.type='button';capture.className='nsc-camera-shutter';capture.disabled=true;capture.textContent=tx('Take photo','گرفتن عکس');const retry=document.createElement('button');retry.type='button';retry.textContent=tx('Retry camera','تلاش دوبارهٔ کمره');retry.hidden=true;
  actions.append(retry,capture);panel.append(header,video,message,actions);overlay.append(panel);document.body.append(overlay);
  let stream,closed=false,pending=false;
  function stop(){stream?.getTracks().forEach(track=>track.stop());stream=null;video.srcObject=null}
  function close(file=null){if(closed)return;closed=true;stop();overlay.remove();document.removeEventListener('keydown',keys,true);current=null;if(previous?.isConnected)previous.focus({preventScroll:true});resolve(file)}
  current={close};cancel.onclick=()=>close();
  function keys(e){if(e.key==='Escape'){e.preventDefault();e.stopImmediatePropagation();close()}if(e.key==='Tab'){e.stopImmediatePropagation();let controls=[...overlay.querySelectorAll('button')].filter(e=>!e.disabled&&!e.hidden);if(e.shiftKey&&document.activeElement===controls[0]){e.preventDefault();controls.at(-1).focus()}else if(!e.shiftKey&&document.activeElement===controls.at(-1)){e.preventDefault();controls[0].focus()}}}
  document.addEventListener('keydown',keys,true);cancel.focus();
  async function start(){if(pending||closed)return;pending=true;retry.hidden=true;capture.disabled=true;stop();try{
   if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia)throw Object.assign(Error(),{name:'InsecureContext'});
   const opened=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1440}}});
   if(closed){opened.getTracks().forEach(t=>t.stop());return}stream=opened;video.srcObject=stream;await video.play();if(closed)return;
   capture.disabled=false;message.textContent=tx('Position the bill inside the camera view, then take a photo.','بل را روبه‌روی کمره بگیر و عکس بگیر.');
  }catch(error){if(!closed){message.textContent=error.name==='NotAllowedError'?tx('Camera permission was denied. Allow camera access in your browser settings, then retry.','اجازهٔ کمره داده نشد. از تنظیمات مرورگر اجازه بده و دوباره تلاش کن.'):error.name==='NotFoundError'?tx('No camera found. Close this window and use Upload.','کمره پیدا نشد. این پنجره را ببند و از آپلود استفاده کن.'):error.name==='InsecureContext'?tx('Camera requires HTTPS and a supported browser. Open the secure site, or use Upload.','کمره به HTTPS و مرورگر پشتیبانی‌شده نیاز دارد. سایت امن را باز کن یا از آپلود استفاده کن.'):tx('Could not start camera. Check permission and close other camera apps, then retry.','کمره باز نشد. اجازهٔ کمره را بررسی کن و برنامه‌های دیگر کمره را ببند، سپس دوباره تلاش کن.');retry.hidden=false}}finally{pending=false}}
  retry.onclick=start;
  capture.onclick=()=>{if(closed||!video.videoWidth)return;capture.disabled=true;const canvas=document.createElement('canvas'),ratio=Math.min(1,2000/Math.max(video.videoWidth,video.videoHeight));canvas.width=Math.round(video.videoWidth*ratio);canvas.height=Math.round(video.videoHeight*ratio);canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);canvas.toBlob(blob=>{if(closed)return;if(!blob){capture.disabled=false;return}close(new File([blob],'camera-'+Date.now()+'.jpg',{type:'image/jpeg'}))},'image/jpeg',.86)};
  start();
 });
};window.addEventListener('pagehide',()=>current?.close());})();
