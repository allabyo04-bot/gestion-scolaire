import { useEffect, useMemo, useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ } from '../composants/commun.jsx';
import ChoixEcole from '../composants/ChoixEcole.jsx';

const VIE_SCOLAIRE = ['SUPER_ADMIN', 'DIRECTRICE', 'SECRETARIAT'];
const CRENEAUX = { MATIN: 'Matin', APRES_MIDI: 'Après-midi' };
export const heuresTexte = (h) => `${String(Number(h)).replace('.', ',')} h`;
const aujourdhui = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const dateLongue = (d) => new Date(d + 'T12:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });

export default function Absences({ vue = 'appel' }) {
  const { utilisateur } = useSession();
  const vieScolaire = VIE_SCOLAIRE.includes(utilisateur.role);
  const [ecoleId, setEcoleId] = useState(utilisateur.ecole?.id ?? null);
  const onglets = [['appel', "Faire l'appel"], ...(vieScolaire ? [['justifier', 'À justifier']] : []), ['bilan', 'Bilan par classe']];
  return (
    <section>
      <div className="entete-page"><h1>Absences et retards</h1><p>L'appel se fait par demi-journée. Les totaux par trimestre iront sur les bulletins.</p></div>
      {vieScolaire && <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} />}
      <nav className="sous-onglets" aria-label="Absences">
        {onglets.map(([id, lib]) => <a key={id} href={`#/absences/${id}`} aria-current={vue === id ? 'page' : undefined}>{lib}</a>)}
      </nav>
      {(ecoleId || !vieScolaire) && vue === 'appel' && <Appel key={ecoleId} ecoleId={ecoleId} />}
      {ecoleId && vieScolaire && vue === 'justifier' && <AJustifier key={ecoleId} ecoleId={ecoleId} />}
      {(ecoleId || !vieScolaire) && vue === 'bilan' && <Bilan key={ecoleId} ecoleId={ecoleId} />}
    </section>
  );
}

function useMesClasses(ecoleId) { return useDonnees(() => api.get('abs/mes_classes', { ecole_id: ecoleId }), [ecoleId]); }

// ------------------------------------------------------------------ Faire l'appel
function Appel({ ecoleId }) {
  const message = useMessage();
  const classes = useMesClasses(ecoleId);
  const [classeId, setClasseId] = useState('');
  const [date, setDate] = useState(aujourdhui());
  const [creneau, setCreneau] = useState(new Date().getHours() < 12 ? 'MATIN' : 'APRES_MIDI');
  useEffect(() => { if (!classeId && classes.donnees?.length) setClasseId(String(classes.donnees[0].id)); }, [classes.donnees, classeId]);
  const appel = useDonnees(() => classeId ? api.get('abs/appel', { classe_id: classeId, date, creneau }) : Promise.resolve(null), [classeId, date, creneau]);
  const [etat, setEtat] = useState({});          // inscription_id → { statut, heures, minutes }
  const [envoi, setEnvoi] = useState(false);

  useEffect(() => {
    if (!appel.donnees) return;
    const e = {};
    for (const x of appel.donnees.eleves) e[x.inscription_id] = { statut: x.statut ?? 'PRESENT', heures: (x.heures ? String(Number(x.heures)) : String(appel.donnees.heures_defaut)).replace('.', ','), minutes: x.minutes ? String(x.minutes) : '10', justifiee: !!Number(x.justifiee) };
    setEtat(e);
  }, [appel.donnees]);

  const compte = useMemo(() => { const c = { PRESENT: 0, ABSENT: 0, RETARD: 0 }; Object.values(etat).forEach((x) => c[x.statut]++); return c; }, [etat]);
  const fige = appel.donnees?.periode && appel.donnees.periode.statut !== 'OUVERTE';
  const changer = (id, k, v) => setEtat((s) => ({ ...s, [id]: { ...s[id], [k]: v } }));
  const enregistrer = async () => {
    setEnvoi(true);
    try {
      const lignes = Object.entries(etat).filter(([, x]) => x.statut !== 'PRESENT').map(([id, x]) => ({ inscription_id: Number(id), statut: x.statut, heures: x.heures, minutes: x.minutes }));
      const r = await api.post('abs/appel_enregistrer', { classe_id: Number(classeId), date, creneau, lignes });
      message(`Appel enregistré : ${r.absents} absent(s), ${r.retards} retard(s).`); appel.recharger();
    } catch (x) { message(x.message, 'erreur'); } finally { setEnvoi(false); }
  };

  if (classes.charge && !classes.donnees) return <Chargement />;
  if (classes.donnees?.length === 0) return <div className="vide"><p>Aucune classe disponible pour l'appel.</p></div>;
  return (
    <div className="zone-feuille">
      <div className="choix-appel">
        <label><span>Classe</span>
          <select value={classeId} onChange={(e) => setClasseId(e.target.value)}>{classes.donnees?.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}</select>
        </label>
        <label><span>Date</span><input type="date" max={aujourdhui()} value={date} onChange={(e) => setDate(e.target.value)} /></label>
        <div className="bascule-creneau" role="radiogroup" aria-label="Demi-journée">
          {Object.entries(CRENEAUX).map(([k, v]) => <button key={k} role="radio" aria-checked={creneau === k} onClick={() => setCreneau(k)}>{v}</button>)}
        </div>
      </div>
      {appel.charge && !appel.donnees && <Chargement />}
      {appel.erreur && <Alerte>{appel.erreur}</Alerte>}
      {appel.donnees && (
        <>
          <p className="resume-classe">{appel.donnees.classe}, {dateLongue(date)} ({CRENEAUX[creneau].toLowerCase()}).
            {appel.donnees.appel_deja_fait ? ' Appel déjà enregistré : vous pouvez le corriger.' : ''}</p>
          {!appel.donnees.periode && <Alerte type="info">Cette date n'appartient à aucun trimestre : vérifiez les dates dans Paramètres.</Alerte>}
          {fige && <Alerte type="info">Le {appel.donnees.periode.libelle} est clôturé : consultation uniquement.</Alerte>}
          <ul className="liste-appel">
            {appel.donnees.eleves.map((x, i) => {
              const s = etat[x.inscription_id] ?? { statut: 'PRESENT' };
              return (
                <li key={x.inscription_id} className={`appel-${s.statut.toLowerCase()}`}>
                  <span className="appel-num">{i + 1}</span>
                  <span className="appel-nom">{x.nom} {x.prenoms}{s.justifiee && <small className="etiquette">Justifiée</small>}</span>
                  <span className="appel-choix" role="radiogroup" aria-label={`${x.nom} ${x.prenoms}`}>
                    {[['PRESENT', 'Présent'], ['ABSENT', 'Absent'], ['RETARD', 'Retard']].map(([k, v]) => (
                      <button key={k} role="radio" aria-checked={s.statut === k} disabled={fige || (s.justifiee && k !== 'ABSENT')}
                              onClick={() => changer(x.inscription_id, 'statut', k)}>{v}</button>))}
                  </span>
                  {s.statut === 'ABSENT' && <label className="appel-detail"><input inputMode="decimal" value={s.heures} disabled={fige} onChange={(e) => changer(x.inscription_id, 'heures', e.target.value)} aria-label="Heures manquées" /> h</label>}
                  {s.statut === 'RETARD' && <label className="appel-detail"><input inputMode="numeric" value={s.minutes} disabled={fige} onChange={(e) => changer(x.inscription_id, 'minutes', e.target.value)} aria-label="Minutes de retard" /> min</label>}
                </li>
              );
            })}
          </ul>
          {!fige && (
            <div className="barre-actions">
              <span className="progression">{compte.PRESENT} présents, {compte.ABSENT} absents, {compte.RETARD} retards</span>
              <button className="bouton bouton-principal" onClick={enregistrer} disabled={envoi}>{envoi ? 'Enregistrement…' : "Enregistrer l'appel"}</button>
            </div>
          )}
        </>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ À justifier
export function FenetreJustifier({ absence, surFermer, surFait }) {
  const message = useMessage();
  const [motif, setMotif] = useState(absence.motif ?? '');
  const [erreur, setErreur] = useState('');
  const envoyer = async (justifiee) => {
    try { await api.post('abs/justifier', { id: absence.id, justifiee, motif }); message(justifiee ? 'Absence justifiée.' : 'Justification retirée.'); surFait(); }
    catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre="Justifier l'absence" surFermer={surFermer} actions={<>
      {Number(absence.justifiee) ? <button className="bouton bouton-danger" onClick={() => envoyer(0)}>Retirer la justification</button> : null}
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={() => envoyer(1)}>Justifier</button></>}>
      <p>{absence.nom ? `${absence.nom} ${absence.prenoms}, ` : ''}{dateLongue(absence.date_absence)} ({CRENEAUX[absence.creneau].toLowerCase()}), {heuresTexte(absence.heures)}.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Motif" id="j-motif" aide="Exemple : certificat médical, deuil, convocation officielle.">
        <input id="j-motif" value={motif} onChange={(e) => setMotif(e.target.value)} autoFocus />
      </Champ>
    </Fenetre>
  );
}

function AJustifier({ ecoleId }) {
  const l = useDonnees(() => api.get('abs/a_justifier', { ecole_id: ecoleId }), [ecoleId]);
  const [choisie, setChoisie] = useState(null);
  if (l.charge && !l.donnees) return <Chargement />;
  if (l.erreur) return <Alerte>{l.erreur}</Alerte>;
  if (!l.donnees.length) return <div className="vide"><p>Aucune absence en attente de justification.</p></div>;
  return (
    <>
      <p className="discret">{l.donnees.length} absence(s) non justifiée(s), les plus récentes d'abord.</p>
      <div className="tableau-defilant">
        <table className="tableau">
          <thead><tr><th scope="col">Élève</th><th scope="col">Date</th><th scope="col" className="nombre">Durée</th><th scope="col"><span className="visuellement-cache">Action</span></th></tr></thead>
          <tbody>{l.donnees.map((a) => (
            <tr key={a.id}>
              <th scope="row">{a.nom} {a.prenoms}<small className="note-ligne">{a.classe}</small></th>
              <td>{dateLongue(a.date_absence)}<small className="note-ligne">{CRENEAUX[a.creneau]}</small></td>
              <td className="nombre">{heuresTexte(a.heures)}</td>
              <td><button className="bouton bouton-discret" onClick={() => setChoisie(a)}>Justifier</button></td>
            </tr>))}
          </tbody>
        </table>
      </div>
      {choisie && <FenetreJustifier absence={choisie} surFermer={() => setChoisie(null)} surFait={() => { setChoisie(null); l.recharger(); }} />}
    </>
  );
}

// ------------------------------------------------------------------ Bilan par classe
function Bilan({ ecoleId }) {
  const { utilisateur } = useSession();
  const classes = useMesClasses(ecoleId);
  const ecole = ecoleId ?? classes.donnees?.[0]?.ecole_id;
  const periodes = useDonnees(() => ecole ? api.get('ref/periodes', { ecole_id: ecole }) : Promise.resolve(null), [ecole]);
  const [classeId, setClasseId] = useState('');
  const [periodeId, setPeriodeId] = useState('');
  useEffect(() => { if (!classeId && classes.donnees?.length) setClasseId(String(classes.donnees[0].id)); }, [classes.donnees, classeId]);
  useEffect(() => { if (!periodeId && periodes.donnees?.length) setPeriodeId(String((periodes.donnees.find((p) => p.statut === 'OUVERTE') ?? periodes.donnees[0]).id)); }, [periodes.donnees, periodeId]);
  const b = useDonnees(() => classeId && periodeId ? api.get('abs/bilan_classe', { classe_id: classeId, periode_id: periodeId }) : Promise.resolve(null), [classeId, periodeId]);
  const tries = [...(b.donnees?.eleves ?? [])].sort((x, y) => (y.heures_non_justifiees + y.heures_justifiees) - (x.heures_non_justifiees + x.heures_justifiees) || x.nom.localeCompare(y.nom));
  return (
    <div>
      <div className="filtres pas-imprimer">
        <label><span>Classe</span><select value={classeId} onChange={(e) => setClasseId(e.target.value)}>{classes.donnees?.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}</select></label>
        <label><span>Période</span><select value={periodeId} onChange={(e) => setPeriodeId(e.target.value)}>{periodes.donnees?.map((p) => <option key={p.id} value={p.id}>{p.libelle}</option>)}</select></label>
      </div>
      {b.charge && !b.donnees && <Chargement />}
      {b.erreur && <Alerte>{b.erreur}</Alerte>}
      {b.donnees && (
        <>
          <h2 className="seulement-imprimer">Absences, {b.donnees.classe}, {b.donnees.periode}</h2>
          <p className="discret">{b.donnees.periode} : du {b.donnees.du.split('-').reverse().join('/')} au {b.donnees.au.split('-').reverse().join('/')}.</p>
          <div className="tableau-defilant">
            <table className="tableau">
              <thead><tr><th scope="col">Élève</th><th scope="col" className="nombre">Justifiées</th><th scope="col" className="nombre">Non justifiées</th><th scope="col" className="nombre">Retards</th></tr></thead>
              <tbody>{tries.map((e) => (
                <tr key={e.inscription_id}>
                  <th scope="row">{VIE_SCOLAIRE.includes(utilisateur.role) ? <a href={`#/eleve/${e.eleve_id}`}>{e.nom} {e.prenoms}</a> : `${e.nom} ${e.prenoms}`}</th>
                  <td className="nombre">{e.heures_justifiees ? heuresTexte(e.heures_justifiees) : '—'}</td>
                  <td className={`nombre${e.heures_non_justifiees ? ' texte-retard' : ''}`}>{e.heures_non_justifiees ? heuresTexte(e.heures_non_justifiees) : '—'}</td>
                  <td className="nombre">{e.retards || '—'}</td>
                </tr>))}
              </tbody>
            </table>
          </div>
          <button className="bouton pas-imprimer" onClick={() => window.print()}>Imprimer le bilan</button>
        </>
      )}
    </div>
  );
}
