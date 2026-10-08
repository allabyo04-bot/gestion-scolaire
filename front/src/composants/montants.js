// 70000 → « 70 000 F »
export const fcfa = (n) => `${Number(n || 0).toLocaleString('fr-FR').replace(/\u202f|\u00a0/g, ' ')} F`;

// Montant en lettres (règles du français : soixante et onze, quatre-vingts, deux cents, mille invariable…)
const UNITES = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize',
  'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
const DIZAINES = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

function moinsDeCent(n, finale = true) {
  if (n < 20) return UNITES[n];
  const d = Math.floor(n / 10), u = n % 10;
  if (d === 7 || d === 9) return DIZAINES[d] + (u === 1 && d === 7 ? ' et ' : '-') + UNITES[10 + u];
  if (u === 0) return DIZAINES[d] + (d === 8 && finale ? 's' : '');
  return DIZAINES[d] + (u === 1 && d !== 8 ? ' et un' : '-' + UNITES[u]);
}
function moinsDeMille(n, finale) {
  const c = Math.floor(n / 100), r = n % 100;
  let t = '';
  if (c > 0) t = (c > 1 ? UNITES[c] + ' cent' : 'cent') + (c > 1 && r === 0 && finale ? 's' : '');
  if (r > 0) t += (t ? ' ' : '') + moinsDeCent(r, finale);
  return t;
}
export function enLettres(montant) {
  let n = Math.floor(Math.abs(Number(montant) || 0));
  if (n === 0) return 'zéro';
  const parties = [];
  const millions = Math.floor(n / 1e6); n %= 1e6;
  const milliers = Math.floor(n / 1000); const reste = n % 1000;
  if (millions) parties.push(moinsDeMille(millions, true) + (millions > 1 ? ' millions' : ' million'));
  if (milliers) parties.push(milliers === 1 ? 'mille' : moinsDeMille(milliers, false) + ' mille');
  if (reste) parties.push(moinsDeMille(reste, true));
  return parties.join(' ');
}
export const enLettresFCFA = (n) => {
  const t = enLettres(n);
  const de = Number(n) >= 1e6 && Number(n) % 1e6 === 0 ? ' de' : '';      // « un million de francs »
  return t.charAt(0).toUpperCase() + t.slice(1) + de + ' francs CFA';
};
