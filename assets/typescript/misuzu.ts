/// <reference path="CurrentUserInfo.ts" />
/// <reference path="Colour.ts" />
/// <reference path="Support.ts" />

declare const timeago: any;
declare const hljs: any;

let userInfo: CurrentUserInfo;
let loginFormAvatarTimeout: number = 0;

// Initialisation process.
window.addEventListener('load', () => {
    timeago().render(document.querySelectorAll('time'));
    hljs.initHighlighting();

    const userInfoElement: HTMLDivElement = document.getElementById('user-info') as HTMLDivElement;

    if (userInfoElement) {
        userInfo = JSON.parse(userInfoElement.textContent) as CurrentUserInfo;

        const colour: Colour = new Colour(userInfo.user_colour);

        console.log(`You are ${userInfo.username} with user id ${userInfo.user_id} and colour ${colour.hex}.`);
    }

    const changelogChangeAction: HTMLDivElement = document.querySelector('.changelog__change__action') as HTMLDivElement;

    if (changelogChangeAction && !Support.sidewaysText) {
        changelogChangeAction.title = "This is supposed to be sideways, but your browser doesn't support that.";
    }

    const loginForms: HTMLCollectionOf<HTMLFormElement> = document.getElementsByClassName('js-login-form') as HTMLCollectionOf<HTMLFormElement>;

    if (loginForms.length > 0) {
        for (let i = 0; i < loginForms.length; i++) {
            const loginForm: HTMLFormElement = loginForms[i],
                loginAvatar: HTMLElement = loginForm.getElementsByClassName('js-login-avatar')[0] as HTMLElement,
                loginUsername: HTMLInputElement = loginForm.getElementsByClassName('js-login-username')[0] as HTMLInputElement;

            // Initial bump, in case anything is prefilled.
            loginFormUpdateAvatar(loginAvatar, loginUsername, true);

            loginUsername.addEventListener('keyup', () => loginFormUpdateAvatar(loginAvatar, loginUsername));
        }
    }
});

function loginFormUpdateAvatar(avatarElement: HTMLElement, usernameElement: HTMLInputElement, force: boolean = false): void {
    if (!force) {
        if (loginFormAvatarTimeout)
            return;

        loginFormAvatarTimeout = setTimeout(() => {
            loginFormUpdateAvatar(avatarElement, usernameElement, true);
            clearTimeout(loginFormAvatarTimeout);
            loginFormAvatarTimeout = 0;
        }, 750);
        return;
    }

    const xhr: XMLHttpRequest = new XMLHttpRequest;
    xhr.addEventListener('readystatechange', () => {
        if (xhr.readyState !== 4)
            return;

        avatarElement.style.backgroundImage = `url('/profile.php?m=avatar&u=${xhr.responseText}')`;
    });
    xhr.open('GET', `/auth.php?m=get_user&u=${encodeURI(usernameElement.value)}`);
    xhr.send();
}
