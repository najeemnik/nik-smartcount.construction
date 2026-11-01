/* The password step never receives a session token before the email challenge succeeds. */
window.NSC_EMAIL_STEP = async function (result, request) {
    if (!result.requiresVerification) return result;
    return new Promise((resolve, reject) => {
        const dialog = document.createElement('dialog');
        dialog.dir = document.documentElement.dir;
        dialog.innerHTML = '<form class="dialog-body"><h2>تأیید ورود با ایمیل</h2><p class="email-hint"></p><label for="email-code">کُد شش‌رقمی ایمیل</label><input id="email-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required dir="ltr"><p class="muted">کُد تا ۵ دقیقه اعتبار دارد. پوشهٔ Spam را هم ببینید.</p><label class="switch"><input name="trust" type="checkbox">اعتماد به این دستگاه تا ۳۰ روز، تا وقتی IP تغییر نکند</label><p class="muted">در دستگاه عمومی این گزینه را انتخاب نکنید.</p><p class="form-error" hidden></p><div class="actions"><button type="button" class="cancel">بازگشت به ورود</button><button type="submit" class="primary">تأیید و ورود</button></div></form>';

    if(NSC_UI.en){let html=dialog.innerHTML;html=html.replace("تأیید ورود با ایمیل","Verify sign-in by email");html=html.replace("کُد شش‌رقمی ایمیل","Six-digit email code");html=html.replace("کُد تا ۵ دقیقه اعتبار دارد. پوشهٔ Spam را هم ببینید.","The code is valid for 5 minutes. Check your spam folder too.");html=html.replace("اعتماد به این دستگاه تا ۳۰ روز، تا وقتی IP تغییر نکند","Trust this device for 30 days, until its IP changes");html=html.replace("در دستگاه عمومی این گزینه را انتخاب نکنید.","Use this option only on a private device.");html=html.replace("بازگشت به ورود","Back to sign in");html=html.replace("تأیید و ورود","Verify and sign in");html=html.replace("حذف دسترسی حساب","Remove account access");html=html.replace("این حساب از فهرست حذف و ورودش بسته می‌شود. اسناد و معلومات مالی شرکت محفوظ می‌ماند.","This account will be removed from the list and blocked from signing in. Company documents and financial records will be kept.");html=html.replace("برای تأیید، نام کاربری حساب را وارد کن","Enter the account username to confirm");html=html.replace("انصراف","Cancel");html=html.replace("حذف حساب","Remove account");dialog.innerHTML=html;}
        dialog.querySelector('.email-hint').textContent = NSC_UI.en ? 'A code was sent to ' + result.emailHint : 'کُد به ' + result.emailHint + ' فرستاده شد.';
        document.body.append(dialog);
        const close = () => { dialog.close(); dialog.remove(); };
        const cancel = () => { close(); reject(Error(NSC_UI.t("Sign-in cancelled. Enter your username and password again for a new code.","ورود لغو شد؛ برای کُد تازه دوباره نام و رمز را وارد کنید."))); };
        dialog.querySelector('.cancel').onclick = cancel;
        dialog.addEventListener('cancel', e => { e.preventDefault(); cancel(); });
        dialog.querySelector('form').onsubmit = async e => {
            e.preventDefault();
            const button = dialog.querySelector('button[type=submit]'), error = dialog.querySelector('.form-error');
            button.disabled = true; error.hidden = true;
            try {
                const verified = await request('auth.php?action=verify_email_code', { challengeId: result.challengeId, code: dialog.querySelector('[name=code]').value, trustDevice: dialog.querySelector('[name=trust]').checked });
                close(); resolve(verified);
            } catch (err) { if(err.data?.accountStatus){close();reject(err);return}error.textContent = err.message; error.hidden = false; button.disabled = false; }
        };
        dialog.showModal(); dialog.querySelector('[name=code]').focus();
    });
};
