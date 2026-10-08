// Adresse de l'API. En production : même domaine, dossier /api/
const BASE = import.meta.env.VITE_API_URL || 'api/index.php';  // relatif : marche aussi dans un sous-dossier
const CLE = 'gs_jeton';

export const jeton = {
  lire: () => { try { return localStorage.getItem(CLE); } catch { return null; } },
  ecrire: (j) => { try { j ? localStorage.setItem(CLE, j) : localStorage.removeItem(CLE); } catch { /* ignore */ } },
};

export class ErreurApi extends Error {
  constructor(message, statut) { super(message); this.statut = statut; }
}

let surSessionExpiree = () => {};
export const quandSessionExpire = (f) => { surSessionExpiree = f; };

async function appel(methode, route, donnees) {
  let url = `${BASE}?r=${route}`;
  const options = { method: methode, headers: {} };
  const j = jeton.lire();
  if (j) { options.headers.Authorization = `Bearer ${j}`; options.headers['X-Jeton'] = j; }
  if (methode === 'GET' && donnees) {
    for (const [k, v] of Object.entries(donnees)) if (v !== undefined && v !== null && v !== '') url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`;
  } else if (donnees) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(donnees);
  }
  let reponse;
  try { reponse = await fetch(url, options); }
  catch { throw new ErreurApi('Connexion au serveur impossible. Vérifiez votre accès internet puis réessayez.', 0); }
  let corps = null;
  try { corps = await reponse.json(); } catch { /* réponse non JSON */ }
  if (!reponse.ok || !corps?.ok) {
    if (reponse.status === 401 && route !== 'auth/connexion') surSessionExpiree();
    throw new ErreurApi(corps?.erreur || `Erreur du serveur (${reponse.status}).`, reponse.status);
  }
  return corps.donnees;
}

// Téléchargement d'un fichier (ex. sauvegarde) avec le jeton de connexion
async function telecharger(route) {
  const j = jeton.lire();
  let r;
  try { r = await fetch(`${BASE}?r=${route}`, { headers: j ? { Authorization: `Bearer ${j}`, 'X-Jeton': j } : {} }); }
  catch { throw new ErreurApi('Connexion au serveur impossible.', 0); }
  if (!r.ok) { let m = `Erreur du serveur (${r.status}).`; try { m = (await r.json()).erreur ?? m; } catch { /* binaire */ } throw new ErreurApi(m, r.status); }
  const nom = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') ?? '')?.[1] ?? 'telechargement';
  const url = URL.createObjectURL(await r.blob());
  const a = Object.assign(document.createElement('a'), { href: url, download: nom });
  document.body.append(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
  return nom;
}

export const api = {
  telecharger,
  get: (route, params) => appel('GET', route, params),
  post: (route, donnees) => appel('POST', route, donnees ?? {}),
};
