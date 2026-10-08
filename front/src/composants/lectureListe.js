// =====================================================================
//  Lecture d'une liste d'élèves : Excel, CSV ou tableau collé depuis Word
//  Repère tout seul les colonnes grâce à la ligne d'en-tête.
// =====================================================================

const sansAccent = (t) => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

// Reconnaît le rôle d'une colonne d'après son titre
function roleColonne(titre) {
  const t = sansAccent(titre).replace(/\*/g, '');
  if (!t) return null;
  if (/educ|matricule/.test(t)) return 'educmaster';
  if (/nom\s*(et|&)\s*prenom/.test(t)) return 'nomPrenoms';
  if (/prenom/.test(t)) return 'prenoms';
  if (/^nom(s)?$|^nom de famille/.test(t)) return 'nom';
  if (/sexe|genre/.test(t)) return 'sexe';
  if (/date/.test(t)) return 'date_naissance';
  if (/lieu/.test(t)) return 'lieu_naissance';
  return null;
}

// « ChamssiathOluwakèmiPrécieuse » → « Chamssiath Oluwakèmi Précieuse » (export collé sans espaces)
const separerMots = (t) => String(t ?? '').replace(/(\p{Ll})(\p{Lu})/gu, '$1 $2').replace(/\s+/g, ' ').trim();

// « OROU GUIDOU Bernadette » → nom « OROU GUIDOU », prénoms « Bernadette »
export function separerNomPrenoms(texte) {
  const mots = String(texte ?? '').replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
  const estMajuscule = (m) => m.length > 1 && !m.endsWith('.') && m === m.toLocaleUpperCase('fr') && /\p{L}/u.test(m);
  let i = 0;
  while (i < mots.length && estMajuscule(mots[i])) i++;
  if (i === 0) i = 1;                         // aucun mot en majuscules : le premier mot est le nom
  if (i === mots.length && mots.length > 1) i = mots.length - 1;   // tout en majuscules : le dernier mot est le prénom
  return { nom: mots.slice(0, i).join(' '), prenoms: mots.slice(i).join(' ') };
}

// Transforme un tableau de lignes (tableaux de cellules) en élèves
export function interpreterTableau(lignes) {
  let entete = -1, roles = [];
  for (let i = 0; i < Math.min(lignes.length, 30); i++) {
    const r = lignes[i].map(roleColonne);
    if (r.includes('nomPrenoms') || (r.includes('nom') && r.includes('prenoms'))) { entete = i; roles = r; break; }
  }
  if (entete < 0) throw new Error("Impossible de trouver la ligne d'en-tête : la liste doit contenir une colonne « Nom et prénoms », ou deux colonnes « Nom » et « Prénom(s) ».");

  const eleves = [];
  for (const cellules of lignes.slice(entete + 1)) {
    const e = {};
    roles.forEach((role, j) => { if (role && e[role] === undefined) e[role] = String(cellules[j] ?? '').trim(); });
    if (cellules.map(roleColonne).filter(Boolean).length >= 2) continue;     // en-tête répété (liste sur plusieurs pages)
    if (e.nomPrenoms !== undefined) Object.assign(e, separerNomPrenoms(e.nomPrenoms));
    delete e.nomPrenoms;
    if (!e.nom && !e.prenoms) continue;                                      // ligne vide
    e.prenoms = separerMots(e.prenoms);
    eleves.push(e);
  }
  return { eleves, colonnes: [...new Set(roles.filter(Boolean))] };
}

// Texte collé depuis Word ou Excel : une ligne par élève, cellules séparées par des tabulations
export function lireTexteColle(texte) {
  const lignes = String(texte).replace(/\r/g, '').split('\n').filter((l) => l.trim())
    .map((l) => (l.includes('\t') ? l.split('\t') : l.split(/;|\s{2,}/)));
  return interpreterTableau(lignes);
}

// Fichier Excel ou CSV : la bibliothèque n'est chargée qu'à ce moment-là
export async function lireFichier(fichier) {
  const XLSX = await import('xlsx');
  const donnees = await fichier.arrayBuffer();
  const classeur = XLSX.read(donnees, { type: 'array', cellDates: false });
  let derniereErreur;
  for (const nom of classeur.SheetNames) {
    const lignes = XLSX.utils.sheet_to_json(classeur.Sheets[nom], { header: 1, raw: false, defval: '', dateNF: 'dd/mm/yyyy' });
    try { return { ...interpreterTableau(lignes), feuille: nom }; } catch (e) { derniereErreur = e; }
  }
  throw derniereErreur ?? new Error('Le fichier est vide.');
}
