import { useEffect, useMemo, useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, gereNotes, aller } from '../composants/commun.jsx';

// Saisie d'une évaluation formative du primaire : tous les élèves × toutes les matières.
// Codes acceptés dans une case : une note de 0 à 20, A (absent) ; pour la direction aussi
// AJ (absent justifié), AN (absent non justifié, compte 0) et D (dispensé).
export const f2 = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(2).replace('.', ','));
const CODES = { ABSENT: 'A', ABSENT_JUSTIFIE: 'AJ', ABSENT_NON_JUSTIFIE: 'AN', DISPENSE: 'D' };
const DEPUIS_CODE = { A: 'ABSENT', AJ: 'ABSENT_JUSTIFIE', AN: 'ABSENT_NON_JUSTIFIE', D: 'DISPENSE' };

function lireCase(texte, direction) {
  const t = String(texte ?? '').trim().toUpperCase().replace(',', '.');
  if (t === '') return null;
  if (DEPUIS_CODE[t]) {
    if (!direction && t !== 'A') return { erreur: `« ${t} » : réservé à la direction` };
    return { statut: DEPUIS_CODE[t], valeur: null };
  }
  if (!/^\d{1,2}(\.\d{1,2})?$/.test(t) || Number(t) > 20) return { erreur: `« ${texte} » n'est pas une note valide` };
  return { statut: 'NOTE', valeur: Number(t) };
}

// Même calcul que le serveur (api/routes/primaire.php, prim_calcul)
export function calculPrimaire(notes, p) {
  let composees = 0, somme = 0, reussies = 0;
  for (const n of notes) {
    if (!n) continue;
    if (n.statut === 'NOTE') { composees++; somme += Number(n.valeur); if (Number(n.valeur) >= p.note_reussite) reussies++; }
    else if (n.statut === 'ABSENT_NON_JUSTIFIE') composees++;
  }
  if (!composees) return null;
  const requis = Math.ceil((composees * p.seuil_num) / p.seuil_den - 1e-9);
  return { moyenne: Math.round((somme / composees) * 100) / 100, seuil: reussies, requis, composees, reussi: reussies >= requis };
}

export default function PrimaireSaisie({ classeId, numero }) {
  const num = Number(numero) || 1;
  const { utilisateur } = useSession();
  const direction = gereNotes(utilisateur);
  const message = useMessage();
  const d = useDonnees(() => api.get('prim/grille', { classe_id: classeId, numero: num }), [classeId, num]);
  const [saisie, setSaisie] = useState({});
  const [modifie, setModifie] = useState(false);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const [correction, setCorrection] = useState(null);
  const [rouvrir, setRouvrir] = useState(false);

  useEffect(() => {
    if (!d.donnees) return;
    const s = {};
    for (const e of d.donnees.eleves)
      for (const [cm, n] of Object.entries(e.notes ?? {}))
        s[`${e.inscription_id}-${cm}`] = n.statut === 'NOTE' ? String(Number(n.valeur)).replace('.', ',') : CODES[n.statut];
    setSaisie(s); setModifie(false); setErreur('');
  }, [d.donnees]);

  const g = d.donnees;
  const verrou = !g || g.statut === 'VALIDEE' || g.periode.statut !== 'OUVERTE';
  const lignes = useMemo(() => (g?.eleves ?? []).map((e) => {
    const cases = g.matieres.map((m) => ({ cm: m.id, texte: saisie[`${e.inscription_id}-${m.id}`] ?? '', lu: lireCase(saisie[`${e.inscription_id}-${m.id}`], direction) }));
    const calc = calculPrimaire(cases.map((c) => (c.lu && !c.lu.erreur ? c.lu : null)), g.parametres);
    return { e, cases, calc, remplies: cases.filter((c) => c.lu && !c.lu.erreur).length, erreurs: cases.filter((c) => c.lu?.erreur) };
  }), [g, saisie, direction]);

  if (d.charge) return <Chargement />;
  if (d.erreur) return <><a href="#/accueil" className="retour">Notes</a><Alerte>{d.erreur}</Alerte></>;

  const p = g.parametres;
  const total = lignes.length * g.matieres.length;
  const remplies = lignes.reduce((s, l) => s + l.remplies, 0);
  const erreurs = lignes.flatMap((l) => l.erreurs.map((x) => `${l.e.nom} ${l.e.prenoms} : ${x.lu.erreur}`));
  const atteints = lignes.filter((l) => l.calc?.reussi).length;

  const changer = (cle, v) => { setSaisie((s) => ({ ...s, [cle]: v })); setModifie(true); };
  const enregistrer = async () => {
    if (erreurs.length) { setErreur(erreurs.slice(0, 3).join(' ; ')); return false; }
    const notes = [];
    for (const l of lignes) for (const c of l.cases) if (c.lu) notes.push({ inscription_id: l.e.inscription_id, classe_matiere_id: c.cm, ...c.lu });
    if (!notes.length) { setErreur('Aucune note à enregistrer.'); return false; }
    setOccupe(true); setErreur('');
    try { await api.post('prim/enregistrer', { classe_id: classeId, numero: num, notes }); setModifie(false); return true; }
    catch (x) { setErreur(x.message); return false; }
    finally { setOccupe(false); }
  };
  const surEnregistrer = async () => { if (await enregistrer()) { message('Notes enregistrées.'); d.recharger(); } };
  const surValider = async () => {
    if (modifie && !(await enregistrer())) return;
    setOccupe(true); setErreur('');
    try { await api.post('prim/valider', { classe_id: classeId, numero: num }); message(`Évaluation ${num} validée.`); d.recharger(); }
    catch (x) { setErreur(x.message); d.recharger(); }
    finally { setOccupe(false); }
  };
  const allerA = (n) => { if (modifie) { setErreur("Enregistrez d'abord vos notes avant de changer d'évaluation."); return; } aller('primaire', classeId, n); };

  const parTrimestre = {};
  for (const ev of g.evaluations) (parTrimestre[ev.trimestre] ??= []).push(ev);
  const etiquetteStatut = { VALIDEE: 'validée', BROUILLON: 'en cours', NOUVELLE: 'à saisir' };

  return (
    <section>
      <a href="#/accueil" className="retour">Notes</a>
      <div className="entete-page entete-avec-action">
        <div>
          <h1>{g.classe.nom} — Évaluation {num}</h1>
          <p>{g.periode.libelle} · {g.matieres.length} matières · une matière est réussie à partir de {String(p.note_reussite).replace('.', ',')}/20 · seuil : {Math.ceil(g.matieres.length * p.seuil_num / p.seuil_den - 1e-9)} matières réussies sur {g.matieres.length}</p>
        </div>
        <div className="groupe-boutons">
          <a className="bouton" href={`#/primaire-bilan/${classeId}/${g.trimestre}`}>Bilan du trimestre</a>
        </div>
      </div>

      <div className="choix-evaluations" role="group" aria-label="Choisir l'évaluation">
        {Object.entries(parTrimestre).map(([t, evs]) => (
          <div key={t} className="groupe-evaluations">
            <span className="discret">{t === '1' ? '1er' : `${t}e`} trimestre</span>
            {evs.map((ev) => (
              <button key={ev.numero} type="button" className={`choix-eval${ev.numero === num ? ' active' : ''}`} aria-pressed={ev.numero === num} onClick={() => allerA(ev.numero)}>
                Évaluation {ev.numero} <small>{etiquetteStatut[ev.statut]}</small>
              </button>
            ))}
          </div>
        ))}
      </div>

      {p.a_confirmer && <Alerte type="info">Règles de calcul par défaut, à confirmer par l'école : seuil des deux tiers des matières, passage avec {p.passage_min} évaluations réussies sur {p.nb_evaluations}.</Alerte>}
      {g.statut === 'VALIDEE' && (
        <Alerte type="info" action={direction && g.periode.statut === 'OUVERTE' ? <button className="bouton" onClick={() => setRouvrir(true)}>Rouvrir pour le titulaire</button> : null}>
          Évaluation validée : les notes sont verrouillées.{direction ? ' Cliquez sur une note pour la corriger (avec un motif).' : ' Seule la direction peut corriger une note.'}
        </Alerte>
      )}
      {g.periode.statut !== 'OUVERTE' && <Alerte type="info">Ce trimestre est clôturé : aucune modification possible.</Alerte>}
      {erreur && <Alerte>{erreur}</Alerte>}

      <div className="tableau-defilant">
        <table className="tableau grille-primaire">
          <thead>
            <tr>
              <th scope="col" className="g">Élève</th>
              {g.matieres.map((m) => <th scope="col" key={m.id} title={m.libelle}>{m.court}</th>)}
              <th scope="col" className="calc">Moyenne</th>
              <th scope="col" className="calc">Seuil</th>
              <th scope="col" className="calc">Résultat</th>
            </tr>
          </thead>
          <tbody>
            {lignes.map(({ e, cases, calc }) => (
              <tr key={e.inscription_id}>
                <th scope="row" className="g">{e.nom} {e.prenoms}</th>
                {cases.map((c) => {
                  const cle = `${e.inscription_id}-${c.cm}`;
                  const bas = c.lu?.statut === 'NOTE' && c.lu.valeur < p.note_reussite;
                  const classe = `case-note${bas ? ' sous-seuil' : ''}${c.lu?.erreur ? ' invalide' : ''}`;
                  if (verrou) {
                    const m = g.matieres.find((x) => x.id === c.cm);
                    return (
                      <td key={c.cm}>
                        {direction && g.statut === 'VALIDEE' && g.periode.statut === 'OUVERTE' && c.texte !== ''
                          ? <button type="button" className={`${classe} corrigeable`} onClick={() => setCorrection({ eleve: e, matiere: m, texte: c.texte })} aria-label={`Corriger ${m.libelle} de ${e.nom} ${e.prenoms}`}>{c.texte}</button>
                          : <span className={classe}>{c.texte || '—'}</span>}
                      </td>
                    );
                  }
                  return (
                    <td key={c.cm}>
                      <input className={classe} value={c.texte} inputMode="decimal" autoComplete="off" maxLength={5}
                             aria-label={`${g.matieres.find((x) => x.id === c.cm).libelle}, ${e.nom} ${e.prenoms}`}
                             onChange={(ev) => changer(cle, ev.target.value)} />
                    </td>
                  );
                })}
                <td className="calc fort">{calc ? f2(calc.moyenne) : '—'}</td>
                <td className="calc fort">{calc ? `${calc.seuil}/${calc.composees}` : '—'}</td>
                <td className="calc">{calc ? <span className={`etiquette ${calc.reussi ? 'etiquette-ok' : 'etiquette-alerte'}`}>{calc.reussi ? 'Atteint' : 'Non atteint'}</span> : ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="pied-saisie">
        <p className="discret">
          <strong>{atteints} élève(s) sur {lignes.length}</strong> ont atteint le seuil · {remplies} case(s) remplie(s) sur {total}.
          {' '}Tapez une note, ou <strong>A</strong> pour un élève absent{direction ? ' (AJ : absent justifié, AN : absent non justifié, compte 0, D : dispensé)' : ''}.
          {modifie && <strong className="a-enregistrer"> Modifications non enregistrées.</strong>}
        </p>
        {!verrou && (
          <div className="groupe-boutons">
            <button className="bouton" onClick={surEnregistrer} disabled={occupe}>Enregistrer</button>
            <button className="bouton bouton-principal" onClick={surValider} disabled={occupe}>Valider l'évaluation</button>
          </div>
        )}
      </div>

      {correction && <FenetreCorrection correction={correction} surFermer={() => setCorrection(null)} surFait={() => { setCorrection(null); d.recharger(); }} />}
      {rouvrir && <FenetreRouvrir classeId={classeId} numero={num} surFermer={() => setRouvrir(false)} surFait={() => { setRouvrir(false); d.recharger(); }} />}
    </section>
  );
}

function FenetreCorrection({ correction, surFermer, surFait }) {
  const message = useMessage();
  const { eleve, matiere } = correction;
  const [texte, setTexte] = useState(correction.texte);
  const [motif, setMotif] = useState('');
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    const lu = lireCase(texte, true);
    if (!lu || lu.erreur) { setErreur(lu?.erreur ?? 'Indiquez une note ou un code.'); return; }
    try {
      await api.post('notes/corriger', { evaluation_id: matiere.evaluation_id, inscription_id: eleve.inscription_id, motif, statut: lu.statut, valeur: lu.valeur });
      message('Note corrigée.'); surFait();
    } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={`Corriger : ${eleve.nom} ${eleve.prenoms}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button><button className="bouton bouton-principal" onClick={envoyer}>Corriger</button></>}>
      <p>{matiere.libelle} — note actuelle : <strong>{correction.texte}</strong></p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Nouvelle note (ou A, AJ, AN, D)" id="c-note"><input id="c-note" value={texte} inputMode="decimal" onChange={(e) => setTexte(e.target.value)} /></Champ>
      <Champ libelle="Motif de la correction" id="c-motif" aide="Obligatoire : il est conservé dans l'historique."><input id="c-motif" value={motif} onChange={(e) => setMotif(e.target.value)} /></Champ>
    </Fenetre>
  );
}

function FenetreRouvrir({ classeId, numero, surFermer, surFait }) {
  const message = useMessage();
  const [motif, setMotif] = useState('');
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    try { await api.post('prim/rouvrir', { classe_id: classeId, numero, motif }); message('Évaluation rouverte : le titulaire peut la reprendre.'); surFait(); }
    catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={`Rouvrir l'évaluation ${numero}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button><button className="bouton bouton-principal" onClick={envoyer}>Rouvrir</button></>}>
      <p>Les notes redeviennent modifiables par le titulaire, qui devra valider à nouveau.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Motif" id="r-motif"><input id="r-motif" value={motif} onChange={(e) => setMotif(e.target.value)} /></Champ>
    </Fenetre>
  );
}
