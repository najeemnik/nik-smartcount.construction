/* The password step never receives a session token before the email challenge succeeds. */
window.NSC_EMAIL_STEP = async function (result, request) {
    if (!result.requiresVerification) return result;
    return new Promise((resolve, reject) => {
        const dialog = document.createElement('dialog');
        dialog.dir = 'rtl';
        dialog.innerHTML = '<form class="dialog-body"><h2>تأیید ورود با ایمیل</h2><p class="email-hint"></p><label for="email-code">کُد شش‌رقمی ایمیل</label><input id="email-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required dir="ltr"><p class="muted">کُد تا ۵ دقیقه اعتبار دارد. پوشهٔ Spam را هم ببینید.</p><label class="switch"><input name="trust" type="checkbox">اعتماد به این دستگاه برای ۵ ورود بعدی (حداکثر ۳۰ روز)</label><p class="muted">در دستگاه عمومی این گزینه را انتخاب نکنید.</p><p class="form-error" hidden></p><div class="actions"><button type="button" class="cancel">بازگشت به ورود</button><button type="submit" class="primary">تأیید و ورود</button></div></form>';
        dialog.querySelector('.email-hint').textContent = 'کُد به ' + result.emailHint + ' فرستاده شد.';
        document.body.append(dialog);
        const close = () => { dialog.close(); dialog.remove(); };
        const cancel = () => { close(); reject(Error('ورود لغو شد؛ برای کُد تازه دوباره نام و رمز را وارد کنید.')); };
        dialog.querySelector('.cancel').onclick = cancel;
        dialog.addEventListener('cancel', e => { e.preventDefault(); cancel(); });
        dialog.querySelector('form').onsubmit = async e => {
            e.preventDefault();
            const button = dialog.querySelector('button[type=submit]'), error = dialog.querySelector('.form-error');
            button.disabled = true; error.hidden = true;
            try {
                const verified = await request('auth.php?action=verify_email_code', { challengeId: result.challengeId, code: dialog.querySelector('[name=code]').value, trustDevice: dialog.querySelector('[name=trust]').checked });
                close(); resolve(verified);
            } catch (err) { error.textContent = err.message; error.hidden = false; button.disabled = false; }
        };
        dialog.showModal(); dialog.querySelector('[name=code]').focus();
    });
};
