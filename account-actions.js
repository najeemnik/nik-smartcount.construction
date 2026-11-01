/* Shared account removal confirmation. Financial/workspace records are never deleted here. */
window.NSC_DELETE_ACCOUNT = function (user, request, refresh) {
    const dialog=document.createElement('dialog');dialog.dir='rtl';
    dialog.innerHTML='<form class="dialog-body"><h2>حذف دسترسی حساب</h2><p data-account></p><p>این حساب از فهرست حذف و ورودش بسته می‌شود. اسناد و معلومات مالی شرکت محفوظ می‌ماند.</p><label for="delete-confirm-name">برای تأیید، نام کاربری حساب را وارد کن</label><input id="delete-confirm-name" autocomplete="off" required dir="ltr"><p class="form-error" hidden></p><div class="actions"><button type="button" data-cancel>انصراف</button><button type="submit" class="danger">حذف حساب</button></div></form>';
    dialog.querySelector('[data-account]').textContent=user.name+' — '+user.username;
    const close=()=>{dialog.close();dialog.remove()};dialog.querySelector('[data-cancel]').onclick=close;
    dialog.addEventListener('cancel',e=>{e.preventDefault();close()});
    dialog.querySelector('form').onsubmit=async e=>{
        e.preventDefault();const error=dialog.querySelector('.form-error'),button=dialog.querySelector('[type=submit]');error.hidden=true;
        if(dialog.querySelector('input').value.trim()!==user.username){error.textContent='نام کاربری را دقیق وارد کنید.';error.hidden=false;return}
        button.disabled=true;
        try{await request('auth.php?action=delete_user',{id:user.id});close();await refresh()}
        catch(err){if(dialog.isConnected){error.textContent=err.message;error.hidden=false;button.disabled=false}}
    };
    document.body.append(dialog);dialog.showModal();dialog.querySelector('input').focus();
};
