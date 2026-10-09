import { useEffect, useMemo, useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, estDirection, formatNote, formatDate } from '../composants/commun.jsx';

const STATUTS = {
  NOTE: 'Note',
  ABSENT: 'Absent (à traiter)',
  ABSENT_JUSTIFIE: 'Absent justifié',
  ABSENT_NON_JUSTIFIE: 'Absent non justifié (0)',
  DISPENSE: 'Dispensé',
};
const COURT = { ABSENT: 'Abs.', ABSENT_JUSTIFIE: 'Abs. J', ABSENT_NON_JUSTIFIE: 'Abs. NJ', DISPENSE: 'Disp.' };
// 13.00 → « 13 », 11.60 → « 11,6 » (plus naturel à modifier)
const noteCourte = (v) => String(Number(v)).replace('.', ',');
const nomEval = (e) => Number(e.est_conduite) ? 'Note de conduite' : `${{ INTERRO: 'Interro', DEVOIR: 'Devoir', DTL: 'DTL' }[e.type]} ${e.numero}`;

// Valeur saisie → nombre valide ou message d'erreur
function lireNote(texte) {
  const t = String(texte).trim().replace(',', '.');
  if (t === '') return { vide: true };
  if (!/^\d{1,2}(\.\d{1,2})?$/.test(t)) return { erreur: 'Format invalide' };
  const n = Number(t);
  if (n > 20) return { erreur: 'Maximum 20' };
  return { valeur: n };
}

export default function SaisieNotes({ classeMatiereId }) {
  const { utilisateur } = useSession();
  const direction = estDirection(utilisateur);
  const affectations = useDonnees(() => api.get('notes/mes_affectations'), []);
  const cm = affectations.donnees?.find((a) => String(a.classe_matiere_id) === String(classeMatiereId));
  const periodes = useDonnees(() => cm ? api.get('ref/periodes', { ecole_id: cm.ecole_id }) : Promise.resolve([]), [cm?.ecole_id]);
  const [periodeId, setPeriodeId] = useState(null);

  useEffect(() => {
    if (!periodeId && periodes.donnees?.length) setPeriodeId((periodes.donnees.find((p) => p.statut === 'OUVERTE') ?? periodes.donnees[0]).id);
  }, [periodes.donnees, periodeId]);

  if (affectations.charge) return <Chargement />;
  if (affectations.erreur) return <Alerte>{affectations.erreur}</Alerte>;
  if (!cm) return <Alerte>Cette matière n'existe pas ou ne vous est pas affectée. <a href="#/accueil">Retour à mes classes</a></Alerte>;

  const periode = periodes.donnees?.find((p) => p.id === periodeId);
  return (
    <section>
      <a href="#/accueil" className="retour">Mes classes</a>
      <div className="entete-page">
        <h1>{cm.matiere} <span className="discret">en {cm.classe}</span></h1>
        <p>Coefficient {Number(cm.coefficient).toString().replace('.', ',')}{direction && cm.professeur ? `, professeur : ${cm.professeur}` : ''}</p>
      </div>
      {periodes.donnees?.length > 1 && (
        <div className="choix-periode" role="tablist" aria-label="Période">
          {periodes.donnees.map((p) => (
            <button key={p.id} role="tab" aria-selected={p.id === periodeId} onClick={() => setPeriodeId(p.id)}>
              {p.libelle}{p.statut === 'CLOTUREE' ? ' (clôturé)' : ''}
            </button>
          ))}
        </div>
      )}
      {periodes.erreur && <Alerte>{periodes.erreur}</Alerte>}
      {periodes.donnees?.length === 0 && <div className="vide"><p>Aucune période n'est ouverte pour l'année en cours.</p></div>}
      {periode && <Evaluations cm={cm} periode={periode} direction={direction} />}
    </section>
  );
}

function Evaluations({ cm, periode, direction }) {
  const message = useMessage();
  const evals = useDonnees(() => api.get('notes/evaluations', { classe_matiere_id: cm.classe_matiere_id, periode_id: periode.id }),
                           [cm.classe_matiere_id, periode.id]);
  const [choisie, setChoisie] = useState(null);
  const liste = evals.donnees ?? [];
  const courante = liste.find((e) => e.id === choisie) ?? liste.find((e) => e.statut === 'BROUILLON') ?? liste[0];

  const ajouter = async (type) => {
    try {
      const r = await api.post('notes/ajouter_evaluation', { classe_matiere_id: cm.classe_matiere_id, periode_id: periode.id, type });
      message(`${{ INTERRO: 'Interro', DEVOIR: 'Devoir', DTL: 'DTL' }[type]} ${r.numero} ajouté(e).`);
      evals.recharger(); setChoisie(r.id);
    } catch (e) { message(e.message, 'erreur'); }
  };

  if (evals.charge && !evals.donnees) return <Chargement />;
  if (evals.erreur) return <Alerte>{evals.erreur}</Alerte>;
  const ouverte = periode.statut === 'OUVERTE';

  return (
    <>
      {liste.length === 0 ? (
        <div className="vide">
          <p>Aucune interro ni aucun devoir n'est prévu pour cette période.</p>
          {direction ? <p>Préparez les évaluations de la classe depuis l'onglet Classes, ou ajoutez-en une ci-dessous.</p>
                     : <p>La direction doit d'abord préparer les évaluations de la classe.</p>}
        </div>
      ) : (
        <div className="choix-evaluation" role="tablist" aria-label="Évaluation">
          {liste.map((e) => (
            <button key={e.id} role="tab" aria-selected={courante?.id === e.id} onClick={() => setChoisie(e.id)}
                    className={e.statut === 'VALIDEE' ? 'est-validee' : Number(e.nb_notes) > 0 ? 'est-entamee' : ''}>
              {nomEval(e)}
              <small>{e.statut === 'VALIDEE' ? 'Validée' : Number(e.nb_notes) > 0 ? 'En cours' : 'À saisir'}</small>
            </button>
          ))}
        </div>
      )}
      {direction && ouverte && (
        <div className="ajouts">
          <button className="bouton bouton-discret" onClick={() => ajouter('INTERRO')}>Ajouter une interro</button>
          <button className="bouton bouton-discret" onClick={() => ajouter('DEVOIR')}>Ajouter un devoir</button>
          <button className="bouton bouton-discret" onClick={() => ajouter('DTL')}>Ajouter un DTL</button>
        </div>
      )}
      {courante && <Feuille key={courante.id} evaluation={courante} ouverte={ouverte} direction={direction}
                            surChangement={evals.recharger} />}
    </>
  );
}

function Feuille({ evaluation, ouverte, direction, surChangement }) {
  const message = useMessage();
  const feuille = useDonnees(() => api.get('notes/feuille', { evaluation_id: evaluation.id }), [evaluation.id]);
  const [saisies, setSaisies] = useState({});       // inscription_id → { texte, statut }
  const [envoi, setEnvoi] = useState(false);
  const [fenetre, setFenetre] = useState(null);     // 'valider' | 'rouvrir' | { correction: eleve }
  const validee = evaluation.statut === 'VALIDEE';
  const modifiable = ouverte && !validee;

  useEffect(() => {
    if (!feuille.donnees) return;
    const s = {};
    for (const e of feuille.donnees.eleves) s[e.inscription_id] = { texte: e.statut === 'NOTE' && e.valeur !== null ? noteCourte(e.valeur) : '', statut: e.statut ?? 'NOTE' };
    setSaisies(s);
  }, [feuille.donnees]);

  const eleves = feuille.donnees?.eleves ?? [];
  const controles = useMemo(() => {
    const c = {};
    for (const e of eleves) {
      const s = saisies[e.inscription_id];
      if (!s) continue;
      const d = s.statut === 'NOTE' ? lireNote(s.texte) : { statut: s.statut };
      const origine = e.statut === 'NOTE' ? noteCourte(e.valeur) : '';
      d.modifie = s.statut !== (e.statut ?? 'NOTE') || (s.statut === 'NOTE' && s.texte.trim() !== origine);
      c[e.inscription_id] = d;
    }
    return c;
  }, [eleves, saisies]);

  const nbErreurs = Object.values(controles).filter((c) => c.erreur).length;
  const nbModifs = Object.values(controles).filter((c) => c.modifie && !c.erreur && !c.vide).length;
  const nbRemplis = eleves.filter((e) => { const s = saisies[e.inscription_id]; return s && (s.statut !== 'NOTE' || s.texte.trim() !== ''); }).length;

  // Prévenir la perte de saisie si on quitte la page
  useEffect(() => {
    if (!nbModifs) return;
    const f = (e) => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', f);
    return () => window.removeEventListener('beforeunload', f);
  }, [nbModifs]);

  const changer = (id, champ, valeur) => setSaisies((s) => ({ ...s, [id]: { ...s[id], [champ]: valeur } }));
  const basculerAbsent = (id) => setSaisies((s) => ({ ...s, [id]: s[id].statut === 'NOTE' ? { texte: '', statut: 'ABSENT' } : { texte: '', statut: 'NOTE' } }));

  const enregistrer = async () => {
    if (nbErreurs) { message('Corrigez les notes signalées en rouge avant d\'enregistrer.', 'erreur'); return false; }
    const notes = Object.entries(controles).filter(([, c]) => c.modifie && !c.vide)
      .map(([id, c]) => c.statut ? { inscription_id: Number(id), statut: c.statut } : { inscription_id: Number(id), valeur: c.valeur });
    if (!notes.length) return true;
    setEnvoi(true);
    try {
      const r = await api.post('notes/enregistrer', { evaluation_id: evaluation.id, notes });
      message(`${r.notes_enregistrees} note(s) enregistrée(s).`);
      feuille.recharger(); surChangement();
      return true;
    } catch (e) { message(e.message, 'erreur'); return false; }
    finally { setEnvoi(false); }
  };

  const valider = async () => {
    if (!(await enregistrer())) return;
    try {
      await api.post('notes/valider', { evaluation_id: evaluation.id });
      message(`${nomEval(evaluation)} validé(e). Les notes sont verrouillées.`);
      setFenetre(null); surChangement();
    } catch (e) { message(e.message, 'erreur'); setFenetre(null); }
  };

  if (feuille.charge && !feuille.donnees) return <Chargement />;
  if (feuille.erreur) return <Alerte>{feuille.erreur}</Alerte>;

  return (
    <div className="zone-feuille">
      {validee && (
        <Alerte type="info" action={direction && ouverte &&
          <button className="bouton bouton-discret" onClick={() => setFenetre('rouvrir')}>Rouvrir pour le professeur</button>}>
          Validé{evaluation.validee_le ? ` le ${formatDate(evaluation.validee_le)}` : ''}. {direction ? 'Pour modifier une note, utilisez « Corriger ».' : 'Seule la direction peut corriger une note.'}
        </Alerte>
      )}
      {!ouverte && <Alerte type="info">Période clôturée : consultation uniquement.</Alerte>}

      <div className="feuille" role="table" aria-label={`Notes — ${nomEval(evaluation)}`}>
        <div className="feuille-entete" role="row">
          <span role="columnheader" className="col-num">N°</span>
          <span role="columnheader" className="col-eleve">Élève</span>
          <span role="columnheader" className="col-note">Note / 20</span>
        </div>
        {eleves.map((e, i) => {
          const s = saisies[e.inscription_id] ?? { texte: '', statut: 'NOTE' };
          const c = controles[e.inscription_id] ?? {};
          return (
            <div key={e.inscription_id} role="row" className={`feuille-ligne${c.erreur ? ' a-erreur' : ''}${c.modifie ? ' est-modifiee' : ''}`}>
              <span role="cell" className="col-num">{i + 1}</span>
              <span role="cell" className="col-eleve">
                <span className="eleve-nom">{e.nom} {e.prenoms}</span>
                {e.educmaster && <span className="eleve-id">{e.educmaster}</span>}
              </span>
              <span role="cell" className="col-note">
                {modifiable ? (
                  <>
                    {s.statut === 'NOTE' ? (
                      <input inputMode="decimal" enterKeyHint="next" aria-label={`Note de ${e.nom} ${e.prenoms}`}
                             aria-invalid={!!c.erreur} value={s.texte} placeholder="—"
                             onChange={(ev) => changer(e.inscription_id, 'texte', ev.target.value)}
                             onKeyDown={(ev) => { if (ev.key === 'Enter') { ev.preventDefault(); ev.currentTarget.closest('.feuille-ligne')?.nextElementSibling?.querySelector('input')?.focus(); } }} />
                    ) : direction ? (
                      <select value={s.statut} aria-label={`Statut de ${e.nom}`} onChange={(ev) => changer(e.inscription_id, 'statut', ev.target.value)}>
                        {Object.entries(STATUTS).filter(([k]) => k !== 'NOTE').map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                      </select>
                    ) : <span className="marque-absent">Absent</span>}
                    <button type="button" className={`bascule-abs${s.statut !== 'NOTE' ? ' actif' : ''}`} onClick={() => basculerAbsent(e.inscription_id)}
                            aria-pressed={s.statut !== 'NOTE'} title={s.statut === 'NOTE' ? 'Marquer absent' : 'Revenir à une note'}>Abs.</button>
                    {c.erreur && <span className="erreur-note">{c.erreur}</span>}
                  </>
                ) : (
                  <>
                    <span className={e.statut && e.statut !== 'NOTE' ? 'marque-absent' : 'note-lue'}>
                      {e.statut === 'NOTE' ? formatNote(e.valeur) : e.statut ? COURT[e.statut] : '—'}
                    </span>
                    {direction && validee && ouverte && e.note_id &&
                      <button type="button" className="bouton-lien" onClick={() => setFenetre({ correction: e })}>Corriger</button>}
                  </>
                )}
              </span>
            </div>
          );
        })}
      </div>

      {modifiable && eleves.length > 0 && (
        <div className="barre-actions">
          <span className="progression">{nbRemplis} / {eleves.length} élèves{nbErreurs ? `, ${nbErreurs} erreur(s)` : ''}</span>
          <button className="bouton" onClick={enregistrer} disabled={envoi || !nbModifs}>{envoi ? 'Enregistrement…' : 'Enregistrer'}</button>
          <button className="bouton bouton-principal" onClick={() => setFenetre('valider')} disabled={envoi || nbRemplis < eleves.length || nbErreurs > 0}>Valider</button>
        </div>
      )}

      {fenetre === 'valider' && (
        <Fenetre titre={`Valider ${nomEval(evaluation).toLowerCase()} ?`} surFermer={() => setFenetre(null)} actions={<>
          <button className="bouton" onClick={() => setFenetre(null)}>Annuler</button>
          <button className="bouton bouton-principal" onClick={valider}>Valider les notes</button></>}>
          <p>Après validation, les notes seront verrouillées. {direction ? '' : 'Pour toute correction, il faudra passer par la direction.'}</p>
          <p>Vérifiez une dernière fois les {eleves.length} notes avant de confirmer.</p>
        </Fenetre>
      )}
      {fenetre === 'rouvrir' && <FenetreRouvrir evaluation={evaluation} surFermer={() => setFenetre(null)} surFait={() => { setFenetre(null); surChangement(); }} />}
      {fenetre?.correction && <FenetreCorrection evaluation={evaluation} eleve={fenetre.correction}
        surFermer={() => setFenetre(null)} surFait={() => { setFenetre(null); feuille.recharger(); }} />}
    </div>
  );
}

function FenetreCorrection({ evaluation, eleve, surFermer, surFait }) {
  const message = useMessage();
  const [statut, setStatut] = useState(eleve.statut);
  const [texte, setTexte] = useState(eleve.statut === 'NOTE' ? noteCourte(eleve.valeur) : '');
  const [motif, setMotif] = useState('');
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    setErreur('');
    const n = statut === 'NOTE' ? lireNote(texte) : {};
    if (n.erreur || n.vide) { setErreur('Saisissez une note valide entre 0 et 20.'); return; }
    if (motif.trim().length < 5) { setErreur('Indiquez le motif de la correction.'); return; }
    try {
      await api.post('notes/corriger', { evaluation_id: evaluation.id, inscription_id: eleve.inscription_id, statut, valeur: n.valeur, motif: motif.trim() });
      message('Note corrigée. La modification est enregistrée dans le journal.'); surFait();
    } catch (e) { setErreur(e.message); }
  };
  return (
    <Fenetre titre={`Corriger : ${eleve.nom} ${eleve.prenoms}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer}>Enregistrer la correction</button></>}>
      <p className="discret">{nomEval(evaluation)}. Note actuelle : <strong>{eleve.statut === 'NOTE' ? formatNote(eleve.valeur) : STATUTS[eleve.statut]}</strong></p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Situation" id="c-statut">
        <select id="c-statut" value={statut} onChange={(e) => setStatut(e.target.value)}>
          {Object.entries(STATUTS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </Champ>
      {statut === 'NOTE' && (
        <Champ libelle="Nouvelle note sur 20" id="c-note">
          <input id="c-note" inputMode="decimal" value={texte} onChange={(e) => setTexte(e.target.value)} />
        </Champ>
      )}
      <Champ libelle="Motif" id="c-motif" aide="Obligatoire. Visible dans le journal et l'historique de la note.">
        <textarea id="c-motif" rows={2} value={motif} onChange={(e) => setMotif(e.target.value)} />
      </Champ>
    </Fenetre>
  );
}

function FenetreRouvrir({ evaluation, surFermer, surFait }) {
  const message = useMessage();
  const [motif, setMotif] = useState('');
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    if (motif.trim().length < 5) { setErreur('Indiquez le motif.'); return; }
    try { await api.post('notes/rouvrir', { evaluation_id: evaluation.id, motif: motif.trim() }); message('Le professeur peut de nouveau modifier ces notes.'); surFait(); }
    catch (e) { setErreur(e.message); }
  };
  return (
    <Fenetre titre={`Rouvrir ${nomEval(evaluation).toLowerCase()}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer}>Rouvrir</button></>}>
      <p>Le professeur pourra de nouveau modifier ces notes, puis devra les valider à nouveau.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Motif" id="r-motif"><textarea id="r-motif" rows={2} value={motif} onChange={(e) => setMotif(e.target.value)} /></Champ>
    </Fenetre>
  );
}
