import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, estDirection } from '../composants/commun.jsx';
import { STATUTS_INSC, LIENS, age, formatTel, incoherence } from './Eleves.jsx';
import { Situation } from './Caisse.jsx';
import { FenetreJustifier, heuresTexte } from './Absences.jsx';

const dateFr = (d) => d ? new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : null;

export default function FicheEleve({ eleveId }) {
  const { utilisateur } = useSession();
  const message = useMessage();
  const e = useDonnees(() => api.get('eleves/fiche', { id: eleveId }), [eleveId]);
  const [fenetre, setFenetre] = useState(null);

  if (e.charge && !e.donnees) return <Chargement />;
  if (e.erreur) return <><a href="#/eleves" className="retour">Élèves</a><Alerte>{e.erreur}</Alerte></>;
  const el = e.donnees;
  const courante = el.inscriptions.find((i) => Number(i.en_cours));
  const fini = () => { setFenetre(null); e.recharger(); };
  const retirerTuteur = async (t) => {
    if (!window.confirm(`Retirer ${t.nom} ${t.prenoms ?? ''} des tuteurs de l'élève ?`)) return;
    try { await api.post('eleves/tuteur_retirer', { eleve_id: el.id, tuteur_id: t.id }); message('Tuteur retiré.'); e.recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };

  return (
    <section>
      <a href={courante ? `#/eleves/${courante.classe_id}` : '#/eleves'} className="retour">{courante ? courante.classe : 'Élèves'}</a>
      <div className="entete-eleve">
        <div className="monogramme" aria-hidden="true">{el.prenoms[0]}{el.nom[0]}</div>
        <div className="entete-eleve-texte">
          <h1>{el.nom} {el.prenoms}</h1>
          {courante?.statut === 'ACTIF' && <a className="bouton bouton-discret lien-certificat" href={`#/document/certificat/${el.id}`}>Certificat de scolarité</a>}
          <p className="discret">{courante ? `${courante.classe}, ${STATUTS_INSC[courante.statut].toLowerCase()}${el.sexe === 'F' && courante.statut !== 'ABANDON' ? 'e' : ''}` : `Pas inscrit${el.sexe === 'F' ? 'e' : ''} cette année`}</p>
        </div>
      </div>

      <div className="grille-fiche">
        <article className="carte-fiche">
          <div className="carte-fiche-titre"><h2>Identité</h2><button className="bouton-lien" onClick={() => setFenetre('identite')}>Modifier</button></div>
          <dl className="details">
            <div><dt>Matricule</dt><dd className="chiffres">{el.matricule}</dd></div>
            <div><dt>Educmaster</dt><dd className="chiffres">{el.educmaster ?? <span className="manque">Non renseigné</span>}</dd></div>
            <div><dt>Sexe</dt><dd>{el.sexe === 'F' ? 'Fille' : 'Garçon'}</dd></div>
            <div><dt>Naissance</dt><dd>{el.date_naissance ? `${dateFr(el.date_naissance)} (${age(el.date_naissance)} ans)` : '—'}{el.lieu_naissance ? `, à ${el.lieu_naissance}` : ''}</dd></div>
            <div><dt>Nationalité</dt><dd>{el.nationalite ?? '—'}</dd></div>
          </dl>
        </article>

        <article className="carte-fiche">
          <div className="carte-fiche-titre"><h2>Tuteurs</h2><button className="bouton-lien" onClick={() => setFenetre({ tuteur: {} })}>Ajouter</button></div>
          {el.tuteurs.length === 0 && <p className="manque">Aucun tuteur enregistré.</p>}
          <ul className="liste-tuteurs">
            {el.tuteurs.map((t) => (
              <li key={t.id}>
                <p><strong>{t.nom} {t.prenoms}</strong>, {LIENS[t.lien].toLowerCase()}{Number(t.principal) ? <small className="etiquette">Principal</small> : null}</p>
                <p><a href={`tel:${t.telephone}`}>{formatTel(t.telephone)}</a>{t.profession ? `, ${t.profession}` : ''}</p>
                <p className="actions-ligne">
                  <button className="bouton-lien" onClick={() => setFenetre({ tuteur: t })}>Modifier</button>
                  <button className="bouton-lien" onClick={() => retirerTuteur(t)}>Retirer</button>
                </p>
              </li>
            ))}
          </ul>
        </article>

        {courante && (
          <article className="carte-fiche carte-large">
            <div className="carte-fiche-titre"><h2>Frais de scolarité</h2><a href={`#/caisse/encaisser/${courante.id}`}>Détail et paiements</a></div>
            <Situation inscriptionId={courante.id} compacte />
          </article>
        )}

        {courante && <CarteAbsences inscriptionId={courante.id} />}

        <article className="carte-fiche carte-large">
          <div className="carte-fiche-titre"><h2>Parcours</h2>{courante && <button className="bouton-lien" onClick={() => setFenetre({ inscription: courante })}>Modifier l'inscription</button>}</div>
          <ol className="parcours">
            {el.inscriptions.map((i) => (
              <li key={i.id} className={Number(i.en_cours) ? 'en-cours' : ''}>
                <span className="parcours-annee">{i.annee}</span>
                <span><strong>{i.classe}</strong>{Number(i.redoublant) ? ' (redoublement)' : ''}, {i.ecole}</span>
                {i.statut !== 'ACTIF' && <small className="etiquette">{STATUTS_INSC[i.statut]}</small>}
              </li>
            ))}
          </ol>
        </article>
      </div>

      {fenetre === 'identite' && <FenetreIdentite eleve={el} surFermer={() => setFenetre(null)} surFait={fini} />}
      {fenetre?.tuteur && <FenetreTuteur eleveId={el.id} tuteur={fenetre.tuteur} surFermer={() => setFenetre(null)} surFait={fini} />}
      {fenetre?.inscription && <FenetreInscriptionModif inscription={fenetre.inscription} direction={estDirection(utilisateur)} ecoleId={courante.ecole_id}
                                  surFermer={() => setFenetre(null)} surFait={fini} />}
    </section>
  );
}

function FenetreIdentite({ eleve, surFermer, surFait }) {
  const message = useMessage();
  const [f, setF] = useState({ ...eleve, educmaster: eleve.educmaster ?? '', date_naissance: eleve.date_naissance ?? '', lieu_naissance: eleve.lieu_naissance ?? '' });
  const [erreur, setErreur] = useState('');
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const envoyer = async () => {
    try { const r = await api.post('eleves/modifier', f); message('Fiche modifiée.'); if (r?.avertissement) message(r.avertissement, 'erreur'); surFait(); } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre="Modifier l'identité" surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button><button className="bouton bouton-principal" onClick={envoyer}>Enregistrer</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Numéro Educmaster" id="f-educ" aide="12 ou 13 chiffres."><input id="f-educ" inputMode="numeric" value={f.educmaster} onChange={maj('educmaster')} /></Champ>
      <div className="deux-colonnes">
        <Champ libelle="Nom" id="f-nom"><input id="f-nom" value={f.nom} onChange={maj('nom')} /></Champ>
        <Champ libelle="Prénoms" id="f-pre"><input id="f-pre" value={f.prenoms} onChange={maj('prenoms')} /></Champ>
      </div>
      {incoherence(f.educmaster, f.sexe) && <p className="avertissement-champ">{incoherence(f.educmaster, f.sexe)}</p>}
      <div className="deux-colonnes">
        <Champ libelle="Sexe" id="f-sexe"><select id="f-sexe" value={f.sexe} onChange={maj('sexe')}><option value="F">Fille</option><option value="M">Garçon</option></select></Champ>
        <Champ libelle="Date de naissance" id="f-ddn"><input id="f-ddn" type="date" value={f.date_naissance} onChange={maj('date_naissance')} /></Champ>
      </div>
      <div className="deux-colonnes">
        <Champ libelle="Lieu de naissance" id="f-lieu"><input id="f-lieu" value={f.lieu_naissance} onChange={maj('lieu_naissance')} /></Champ>
        <Champ libelle="Nationalité" id="f-nat"><input id="f-nat" value={f.nationalite ?? ''} onChange={maj('nationalite')} /></Champ>
      </div>
    </Fenetre>
  );
}

function FenetreTuteur({ eleveId, tuteur, surFermer, surFait }) {
  const message = useMessage();
  const [t, setT] = useState({ nom: tuteur.nom ?? '', prenoms: tuteur.prenoms ?? '', telephone: tuteur.telephone ?? '', email: tuteur.email ?? '',
                               profession: tuteur.profession ?? '', lien: tuteur.lien ?? 'MERE', principal: !!Number(tuteur.principal ?? 0) });
  const [erreur, setErreur] = useState('');
  const maj = (k) => (e) => setT({ ...t, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });
  const envoyer = async () => {
    try { await api.post('eleves/tuteur_enregistrer', { ...t, eleve_id: eleveId }); message('Tuteur enregistré.'); surFait(); } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={tuteur.id ? 'Modifier le tuteur' : 'Ajouter un tuteur'} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button><button className="bouton bouton-principal" onClick={envoyer}>Enregistrer</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      <p className="discret">Un même numéro de téléphone désigne un même tuteur : les frères et sœurs sont ainsi reliés automatiquement.</p>
      <div className="deux-colonnes">
        <Champ libelle="Nom" id="tt-nom"><input id="tt-nom" value={t.nom} onChange={maj('nom')} /></Champ>
        <Champ libelle="Prénoms" id="tt-pre"><input id="tt-pre" value={t.prenoms} onChange={maj('prenoms')} /></Champ>
      </div>
      <div className="deux-colonnes">
        <Champ libelle="Téléphone" id="tt-tel"><input id="tt-tel" type="tel" value={t.telephone} onChange={maj('telephone')} disabled={!!tuteur.id} /></Champ>
        <Champ libelle="Lien avec l'élève" id="tt-lien">
          <select id="tt-lien" value={t.lien} onChange={maj('lien')}>{Object.entries(LIENS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
        </Champ>
      </div>
      <div className="deux-colonnes">
        <Champ libelle="Profession (facultatif)" id="tt-pro"><input id="tt-pro" value={t.profession} onChange={maj('profession')} /></Champ>
        <Champ libelle="E-mail (facultatif)" id="tt-mail"><input id="tt-mail" type="email" value={t.email} onChange={maj('email')} /></Champ>
      </div>
      <label className="case"><input type="checkbox" checked={t.principal} onChange={maj('principal')} /> Tuteur principal (premier contact de l'école)</label>
    </Fenetre>
  );
}

function FenetreInscriptionModif({ inscription, direction, ecoleId, surFermer, surFait }) {
  const message = useMessage();
  const classes = useDonnees(() => api.get('ref/classes', { ecole_id: ecoleId }), [ecoleId]);
  const [f, setF] = useState({ classe_id: inscription.classe_id, statut: inscription.statut, redoublant: !!Number(inscription.redoublant) });
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    try { await api.post('eleves/inscription_modifier', { id: inscription.id, ...f }); message('Inscription modifiée.'); surFait(); } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={`Inscription ${inscription.annee}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button><button className="bouton bouton-principal" onClick={envoyer}>Enregistrer</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Classe" id="m-cl" aide="Changement possible seulement tant qu'aucune note n'a été saisie pour l'élève.">
        <select id="m-cl" value={f.classe_id} onChange={(e) => setF({ ...f, classe_id: Number(e.target.value) })}>
          {classes.donnees?.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}
        </select>
      </Champ>
      <Champ libelle="Situation" id="m-st">
        <select id="m-st" value={f.statut} onChange={(e) => setF({ ...f, statut: e.target.value })}>
          {Object.entries(STATUTS_INSC).filter(([k]) => direction || k !== 'EXCLU' || f.statut === 'EXCLU').map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </Champ>
      <label className="case"><input type="checkbox" checked={f.redoublant} onChange={(e) => setF({ ...f, redoublant: e.target.checked })} /> Redouble cette classe</label>
    </Fenetre>
  );
}

function CarteAbsences({ inscriptionId }) {
  const a = useDonnees(() => api.get('abs/eleve', { inscription_id: inscriptionId }), [inscriptionId]);
  const [choisie, setChoisie] = useState(null);
  if (!a.donnees) return null;
  const { periodes, absences } = a.donnees;
  return (
    <article className="carte-fiche carte-large">
      <div className="carte-fiche-titre"><h2>Absences et retards</h2></div>
      <div className="tableau-defilant">
        <table className="tableau">
          <thead><tr><th scope="col">Période</th><th scope="col" className="nombre">Justifiées</th><th scope="col" className="nombre">Non justifiées</th><th scope="col" className="nombre">Retards</th></tr></thead>
          <tbody>{periodes.map((p) => (
            <tr key={p.libelle}><th scope="row">{p.libelle}</th><td className="nombre">{heuresTexte(p.heures_justifiees)}</td>
              <td className={`nombre${p.heures_non_justifiees ? ' texte-retard' : ''}`}>{heuresTexte(p.heures_non_justifiees)}</td><td className="nombre">{p.retards}</td></tr>))}
          </tbody>
        </table>
      </div>
      {absences.length > 0 && (
        <ul className="liste-simple">
          {absences.slice(0, 10).map((x) => (
            <li key={x.id}>
              <span>{x.date_absence.split('-').reverse().join('/')}, {x.creneau === 'MATIN' ? 'matin' : 'après-midi'} : {x.statut === 'ABSENT' ? `absent ${heuresTexte(x.heures)}` : `retard ${x.minutes} min`}</span>
              {x.statut === 'ABSENT' && (Number(x.justifiee) ? <small className="etiquette">Justifiée : {x.motif}</small> : <small className="etiquette etiquette-alerte">Non justifiée</small>)}
              {x.statut === 'ABSENT' && <button className="bouton-lien" onClick={() => setChoisie(x)}>{Number(x.justifiee) ? 'Modifier' : 'Justifier'}</button>}
            </li>))}
        </ul>
      )}
      {choisie && <FenetreJustifier absence={choisie} surFermer={() => setChoisie(null)} surFait={() => { setChoisie(null); a.recharger(); }} />}
    </article>
  );
}
