/* Shared account removal confirmation. Financial/workspace records are never deleted here. */
window.NSC_DELETE_ACCOUNT = function (user, request, refresh) {
    const dialog=document.createElement('dialog');dialog.dir=document.documentElement.dir;
    dialog.innerHTML='<form class="dialog-body"><h2>حذف دسترسی حساب</h2><p data-account></p><p>این حساب از فهرست حذف و ورودش بسته می‌شود. اسناد و معلومات مالی شرکت محفوظ می‌ماند.</p><label for="delete-confirm-name">برای تأیید، نام کاربری حساب را وارد کن</label><input id="delete-confirm-name" autocomplete="off" required dir="ltr"><p class="form-error" hidden></p><div class="actions"><button type="button" data-cancel>انصراف</button><button type="submit" class="danger">حذف حساب</button></div></form>';

    if(NSC_UI.en){let html=dialog.innerHTML;html=html.replace("تأیید ورود با ایمیل","Verify sign-in by email");html=html.replace("کُد شش‌رقمی ایمیل","Six-digit email code");html=html.replace("کُد تا ۵ دقیقه اعتبار دارد. پوشهٔ Spam را هم ببینید.","The code is valid for 5 minutes. Check your spam folder too.");html=html.replace("اعتماد به این دستگاه تا ۳۰ روز، تا وقتی IP تغییر نکند","Trust this device for 30 days, until its IP changes");html=html.replace("در دستگاه عمومی این گزینه را انتخاب نکنید.","Use this option only on a private device.");html=html.replace("بازگشت به ورود","Back to sign in");html=html.replace("تأیید و ورود","Verify and sign in");html=html.replace("حذف دسترسی حساب","Remove account access");html=html.replace("این حساب از فهرست حذف و ورودش بسته می‌شود. اسناد و معلومات مالی شرکت محفوظ می‌ماند.","This account will be removed from the list and blocked from signing in. Company documents and financial records will be kept.");html=html.replace("برای تأیید، نام کاربری حساب را وارد کن","Enter the account username to confirm");html=html.replace("انصراف","Cancel");html=html.replace("حذف حساب","Remove account");dialog.innerHTML=html;}
    dialog.querySelector('[data-account]').textContent=user.name+' — '+user.username;
    const close=()=>{dialog.close();dialog.remove()};dialog.querySelector('[data-cancel]').onclick=close;
    dialog.addEventListener('cancel',e=>{e.preventDefault();close()});
    dialog.querySelector('form').onsubmit=async e=>{
        e.preventDefault();const error=dialog.querySelector('.form-error'),button=dialog.querySelector('[type=submit]');error.hidden=true;
        if(dialog.querySelector('input').value.trim()!==user.username){error.textContent=NSC_UI.t("Enter the exact username.","نام کاربری را دقیق وارد کنید.");error.hidden=false;return}
        button.disabled=true;
        try{await request('auth.php?action=delete_user',{id:user.id});close();await refresh()}
        catch(err){if(dialog.isConnected){error.textContent=err.message;error.hidden=false;button.disabled=false}}
    };
    document.body.append(dialog);dialog.showModal();dialog.querySelector('input').focus();
};
