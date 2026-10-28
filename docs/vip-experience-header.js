import {initI18n, addLanguage, initLangSwitch, preferredLang, setLang} from '../js/i18n.js?v=93';
import {initFooter} from '../js/sections/footer.js?v=93';

const themeButton = document.getElementById('themeBtn');
themeButton.dataset.now = document.documentElement.dataset.theme;
themeButton.setAttribute('aria-pressed', String(themeButton.dataset.now === 'dark'));
themeButton.addEventListener('click', () => {
  const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  document.documentElement.dataset.theme = theme;
  themeButton.dataset.now = theme;
  themeButton.setAttribute('aria-pressed', String(theme === 'dark'));
});

const [english, coreEnglish] = await Promise.all(['registration-en', 'en'].map(name => fetch(new URL(`../data/i18n/${name}.json`, import.meta.url)).then(response => response.json())));
initI18n({...coreEnglish, ...english});
await Promise.all(['ar', 'ku'].map(async (language) => {
  const response = await fetch(new URL(`../data/i18n/${language}.json`, import.meta.url));
  if (!response.ok) throw new Error(`Language could not load: ${language}`);
  const extra = await fetch(new URL(`../data/i18n/registration-${language}.json`, import.meta.url)).then(response => response.json());
  addLanguage(language, {...await response.json(), ...extra});
}));
initLangSwitch();
initFooter(JSON.parse(document.getElementById('experienceConfig').textContent).footer);
const language = new URLSearchParams(location.search).get('lang');
setLang(['en', 'ar', 'ku'].includes(language) ? language : preferredLang());
await import('./vip-experience.js');
window.vipExperienceHeaderReady = true;
