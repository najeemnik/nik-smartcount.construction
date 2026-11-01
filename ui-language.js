/* A saved user choice wins; new users start in English. */
(() => {
 const language=()=>localStorage.getItem('nsc_portal_lang')||'en';
 const translate=(root=document)=>{for(const node of [...(root.nodeType===1&&root.matches('[data-en]')?[root]:[]),...root.querySelectorAll('[data-en]')]){node.dataset.fa??=node.textContent;const text=language()==='en'?node.dataset.en:node.dataset.fa;if(node.textContent!==text)node.textContent=text}};
 const sync=()=>{document.documentElement.lang=language();document.documentElement.dir=language()==='en'?'ltr':'rtl';translate()};
 window.NSC_UI={get lang(){return language()},get en(){return language()==='en'},t:(en,fa)=>language()==='en'?en:fa,set:lang=>{if(!['en','fa','ps'].includes(lang))return;localStorage.setItem('nsc_portal_lang',lang);sync();window.dispatchEvent(new Event('nsc:language'))}};
 sync();document.addEventListener('DOMContentLoaded',()=>{sync();new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)translate(node)}).observe(document.body,{childList:true,subtree:true})});
 window.addEventListener('nsc:language',sync);
})();
