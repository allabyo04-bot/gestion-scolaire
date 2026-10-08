import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ } from '../composants/commun.jsx';
import ChoixEcole from '../composants/ChoixEcole.jsx';
import ParamClasses from './ParamClasses.jsx';

const SECTIONS = [['ecole', 'École'], ['annee', 'Année et périodes'], ['classes', 'Classes et matières'], ['evaluations', 'Interros et devoirs']];

export default function Parametres({ section = 'ecole', sousParam }) {
  const { utilisateur } = useSession();
  const [ecoleId, setEcoleId] = useState(utilisateur.ecole?.id ?? null);
  return (
    <section>
      <div className="entete-page"><h1>Paramètres</h1><p>Ce qui est réglé ici s'applique à toute l'école.</p></div>
      <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} />
      <nav className="sous-onglets" aria-label="Sections des paramètres">
        {SECTIONS.map(([id, lib]) => <a key={id} href={`#/parametres/${id}`} aria-current={section === id ? 'page' : undefined}>{lib}</a>)}
      </nav>
      {ecoleId && section === 'ecole' && <FicheEcole key={ecoleId} ecoleId={ecoleId} />}
      {ecoleId && section === 'annee' && <AnneePeriodes key={ecoleId} ecoleId={ecoleId} />}
      {ecoleId && section === 'classes' && <ParamClasses key={ecoleId} ecoleId={ecoleId} classeId={sousParam} />}
      {ecoleId && section === 'evaluations' && <ConfigEvaluations key={ecoleId} ecoleId={ecoleId} />}
      {utilisateur.role === 'SUPER_ADMIN' && section === 'ecole' && <NouvelleEcole />}
    </section>
  );
}

// ------------------------------------------------------------------ École
const CHAMPS = [
  ['nom_officiel', 'Nom officiel de l\'école', 'Tel qu\'il doit apparaître sur les bulletins.'],
  ['sigle', 'Sigle (facultatif)'], ['ville', 'Ville'], ['adresse', 'Adresse'], ['boite_postale', 'Boîte postale'],
  ['telephone', 'Téléphone'], ['email', 'E-mail'],
  ['entete_ligne1', 'Entête : ligne 1', 'Exemple : République du Bénin'],
  ['entete_ligne2', 'Entête : ligne 2', 'Exemple : Ministère des Enseignements secondaire, technique et de la formation professionnelle'],
  ['entete_ligne3', 'Entête : ligne 3', 'Exemple : Direction départementale de l\'Alibori'],
  ['devise', 'Devise de l\'école'], ['nom_directrice', 'Nom du signataire des bulletins'], ['titre_signataire', 'Titre du signataire', 'Exemple : La Directrice'],
];

function FicheEcole({ ecoleId }) {
  const message = useMessage();
  const e = useDonnees(() => api.get('param/ecole', { ecole_id: ecoleId }), [ecoleId]);
  const [f, setF] = useState(null);
  const [erreur, setErreur] = useState('');
  if (e.charge) return <Chargement />;
  if (e.erreur) return <Alerte>{e.erreur}</Alerte>;
  const v = f ?? e.donnees;
  const envoyer = async (ev) => {
    ev.preventDefault(); setErreur('');
    try { await api.post('param/ecole_enregistrer', { ...v, id: ecoleId, ecole_id: ecoleId }); message('Fiche de l\'école enregistrée.'); setF(null); e.recharger(); }
    catch (x) { setErreur(x.message); }
  };
  return (
    <form className="formulaire-large" onSubmit={envoyer}>
      <h2>Fiche de l'école</h2>
      <p className="discret">Le logo, le cachet et la signature seront ajoutés avec le module des bulletins.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <div className="grille-champs">
        {CHAMPS.map(([k, lib, aide]) => (
          <Champ key={k} libelle={lib} id={`e-${k}`} aide={aide}>
            <input id={`e-${k}`} value={v[k] ?? ''} onChange={(x) => setF({ ...v, [k]: x.target.value })} />
          </Champ>
        ))}
      </div>
      <button className="bouton bouton-principal" disabled={!f}>Enregistrer la fiche</button>
    </form>
  );
}

function NouvelleEcole() {
  const message = useMessage();
  const [ouvert, setOuvert] = useState(false);
  const [f, setF] = useState({ code: '', nom_officiel: '', ville: '' });
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    try { await api.post('param/ecole_enregistrer', f); message(`École « ${f.nom_officiel} » créée.`); setOuvert(false); window.location.reload(); }
    catch (x) { setErreur(x.message); }
  };
  return (
    <div className="zone-secondaire">
      <h2>Ajouter une école au réseau</h2>
      <p className="discret">Chaque école a ses propres classes, élèves, comptes et bulletins.</p>
      <button className="bouton" onClick={() => setOuvert(true)}>Ajouter une école</button>
      {ouvert && (
        <Fenetre titre="Nouvelle école" surFermer={() => setOuvert(false)} actions={<>
          <button className="bouton" onClick={() => setOuvert(false)}>Annuler</button>
          <button className="bouton bouton-principal" onClick={envoyer}>Créer l'école</button></>}>
          {erreur && <Alerte>{erreur}</Alerte>}
          <Champ libelle="Code court" id="n-code" aide="Sert à numéroter les matricules (ex. NDALI → NDALI-26-0001). Non modifiable ensuite.">
            <input id="n-code" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '') })} />
          </Champ>
          <Champ libelle="Nom officiel" id="n-nom"><input id="n-nom" value={f.nom_officiel} onChange={(e) => setF({ ...f, nom_officiel: e.target.value })} /></Champ>
          <Champ libelle="Ville" id="n-ville"><input id="n-ville" value={f.ville} onChange={(e) => setF({ ...f, ville: e.target.value })} /></Champ>
        </Fenetre>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ Année et périodes
function AnneePeriodes({ ecoleId }) {
  const { utilisateur } = useSession();
  const message = useMessage();
  const d = useDonnees(() => api.get('param/periodes', { ecole_id: ecoleId }), [ecoleId]);
  const [type, setType] = useState('TRIMESTRE');
  const [reglages, setReglages] = useState(null);

  const creer = async () => {
    try { await api.post('param/periodes_creer', { ecole_id: ecoleId, type_periode: type }); message('Découpage de l\'année créé.'); d.recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };
  const basculer = async (p) => {
    const cloturer = p.statut === 'OUVERTE';
    if (cloturer && !window.confirm(`Clôturer le ${p.libelle} ? Plus aucune note ne pourra être saisie ni corrigée sur cette période.`)) return;
    try { await api.post('param/periode_statut', { id: p.id, statut: cloturer ? 'CLOTUREE' : 'OUVERTE' }); message(cloturer ? `${p.libelle} clôturé.` : `${p.libelle} rouvert.`); d.recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };
  const enregistrer = async () => {
    try { await api.post('param/parametres_enregistrer', { ecole_id: ecoleId, ...reglages }); message('Règles de calcul enregistrées.'); setReglages(null); d.recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };

  return (
    <div>
      {utilisateur.role === 'SUPER_ADMIN' && <Annees surChangement={d.recharger} />}
      {d.charge && <Chargement />}
      {d.erreur && <Alerte>{d.erreur}</Alerte>}
      {d.donnees && (
        <>
          <h2 className="titre-section">Année {d.donnees.annee.libelle}</h2>
          {d.donnees.periodes.length === 0 ? (
            <div className="vide">
              <p>L'année n'est pas encore découpée pour cette école.</p>
              <div className="ligne-formulaire">
                <select value={type} onChange={(e) => setType(e.target.value)} aria-label="Découpage">
                  <option value="TRIMESTRE">3 trimestres</option><option value="SEMESTRE">2 semestres</option>
                </select>
                <button className="bouton bouton-principal" onClick={creer}>Créer le découpage</button>
              </div>
            </div>
          ) : (
            <>
              <div className="tableau-defilant">
                <table className="tableau">
                  <thead><tr><th scope="col">Période</th><th scope="col">État</th><th scope="col"><span className="visuellement-cache">Action</span></th></tr></thead>
                  <tbody>{d.donnees.periodes.map((p) => (
                    <tr key={p.id}>
                      <th scope="row">{p.libelle}</th>
                      <td>{p.statut === 'OUVERTE' ? <span className="pastille pastille-ouverte">Ouverte</span> : <span className="pastille pastille-close">Clôturée</span>}</td>
                      <td><button className="bouton-lien" onClick={() => basculer(p)}>{p.statut === 'OUVERTE' ? 'Clôturer' : 'Rouvrir'}</button></td>
                    </tr>))}
                  </tbody>
                </table>
              </div>
              <ReglesCalcul donnees={d.donnees} reglages={reglages} setReglages={setReglages} surEnregistrer={enregistrer} />
            </>
          )}
        </>
      )}
    </div>
  );
}

function ReglesCalcul({ donnees, reglages, setReglages, surEnregistrer }) {
  const base = { mode_moy_annuelle: donnees.parametres?.mode_moy_annuelle ?? 'SIMPLE',
                 moyenne_passage: String(Number(donnees.parametres?.moyenne_passage ?? 10)).replace('.', ','),
                 poids: Object.fromEntries(donnees.periodes.map((p) => [p.id, String(Number(p.poids)).replace('.', ',')])) };
  const v = reglages ?? base;
  const maj = (k, x) => setReglages({ ...v, [k]: x });
  return (
    <div className="formulaire-large">
      <h2 className="titre-section">Règles de calcul de l'année</h2>
      <div className="grille-champs">
        <Champ libelle="Moyenne annuelle" id="r-mode">
          <select id="r-mode" value={v.mode_moy_annuelle} onChange={(e) => maj('mode_moy_annuelle', e.target.value)}>
            <option value="SIMPLE">Moyenne simple des périodes</option>
            <option value="PONDEREE">Moyenne pondérée (poids par période)</option>
          </select>
        </Champ>
        <Champ libelle="Moyenne de passage" id="r-passage" aide="Sur 20. Sert aux décisions de fin d'année.">
          <input id="r-passage" inputMode="decimal" value={v.moyenne_passage} onChange={(e) => maj('moyenne_passage', e.target.value)} />
        </Champ>
      </div>
      {v.mode_moy_annuelle === 'PONDEREE' && (
        <div className="grille-champs">
          {donnees.periodes.map((p) => (
            <Champ key={p.id} libelle={`Poids du ${p.libelle}`} id={`r-p${p.id}`}>
              <input id={`r-p${p.id}`} inputMode="decimal" value={v.poids[p.id]} onChange={(e) => maj('poids', { ...v.poids, [p.id]: e.target.value })} />
            </Champ>
          ))}
        </div>
      )}
      <button className="bouton bouton-principal" disabled={!reglages} onClick={surEnregistrer}>Enregistrer les règles</button>
    </div>
  );
}

function Annees({ surChangement }) {
  const message = useMessage();
  const a = useDonnees(() => api.get('param/annees'), []);
  const [libelle, setLibelle] = useState('');
  const creer = async () => {
    try { await api.post('param/annee_creer', { libelle }); message(`Année ${libelle} créée.`); setLibelle(''); a.recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };
  const activer = async (x) => {
    if (!window.confirm(`Faire de ${x.libelle} l'année en cours pour TOUT le réseau ?`)) return;
    try { await api.post('param/annee_activer', { id: x.id }); message(`${x.libelle} est maintenant l'année en cours.`); a.recharger(); surChangement(); }
    catch (e) { message(e.message, 'erreur'); }
  };
  return (
    <div className="zone-secondaire">
      <h2>Années scolaires du réseau</h2>
      <ul className="liste-simple">
        {a.donnees?.map((x) => (
          <li key={x.id}>{x.libelle} {Number(x.en_cours) ? <span className="pastille pastille-ouverte">Année en cours</span>
            : <button className="bouton-lien" onClick={() => activer(x)}>En faire l'année en cours</button>}</li>
        ))}
      </ul>
      <div className="ligne-formulaire">
        <input placeholder="2027-2028" aria-label="Nouvelle année" value={libelle} onChange={(e) => setLibelle(e.target.value)} />
        <button className="bouton" onClick={creer} disabled={!libelle}>Créer l'année</button>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ Interros et devoirs par défaut
function ConfigEvaluations({ ecoleId }) {
  const message = useMessage();
  const c = useDonnees(() => api.get('param/config_eval', { ecole_id: ecoleId }), [ecoleId]);
  const niveaux = useDonnees(() => api.get('param/niveaux'), []);
  const [edition, setEdition] = useState(null);
  const enregistrer = async () => {
    try { await api.post('param/config_eval_enregistrer', { ecole_id: ecoleId, ...edition }); message('Paramétrage enregistré.'); setEdition(null); c.recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };
  const supprimer = async (x) => {
    try { await api.post('param/config_eval_supprimer', { id: x.id }); message('Exception supprimée.'); c.recharger(); }
    catch (e) { message(e.message, 'erreur'); }
  };
  if (c.charge) return <Chargement />;
  const general = c.donnees?.find((x) => x.niveau_id === null);
  const exceptions = c.donnees?.filter((x) => x.niveau_id !== null) ?? [];
  return (
    <div>
      <h2 className="titre-section">Nombre d'interros et de devoirs par période</h2>
      <p className="discret texte-explicatif">C'est ce qui sera créé quand vous préparez les évaluations d'une classe. Vous pourrez ensuite, matière par matière, ajouter ou retirer une interro. La conduite reçoit toujours une seule note.</p>
      <div className="tableau-defilant">
        <table className="tableau">
          <thead><tr><th scope="col">S'applique à</th><th scope="col" className="nombre">Interros</th><th scope="col" className="nombre">Devoirs</th><th scope="col"><span className="visuellement-cache">Actions</span></th></tr></thead>
          <tbody>
            <tr><th scope="row">Tous les niveaux</th>
              <td className="nombre">{general?.nb_interros ?? '—'}</td><td className="nombre">{general?.nb_devoirs ?? '—'}</td>
              <td><button className="bouton-lien" onClick={() => setEdition({ niveau_id: null, nb_interros: general?.nb_interros ?? 3, nb_devoirs: general?.nb_devoirs ?? 2 })}>Modifier</button></td></tr>
            {exceptions.map((x) => (
              <tr key={x.id}><th scope="row">{x.niveau} seulement</th>
                <td className="nombre">{x.nb_interros}</td><td className="nombre">{x.nb_devoirs}</td>
                <td className="actions-ligne"><button className="bouton-lien" onClick={() => setEdition({ niveau_id: x.niveau_id, nb_interros: x.nb_interros, nb_devoirs: x.nb_devoirs })}>Modifier</button>
                  <button className="bouton-lien" onClick={() => supprimer(x)}>Supprimer</button></td></tr>
            ))}
          </tbody>
        </table>
      </div>
      <button className="bouton" onClick={() => setEdition({ niveau_id: '', nb_interros: 3, nb_devoirs: 2, nouveau: true })}>Ajouter une exception pour un niveau</button>
      {edition && (
        <Fenetre titre={edition.nouveau ? 'Exception pour un niveau' : 'Modifier le paramétrage'} surFermer={() => setEdition(null)} actions={<>
          <button className="bouton" onClick={() => setEdition(null)}>Annuler</button>
          <button className="bouton bouton-principal" onClick={enregistrer} disabled={edition.nouveau && !edition.niveau_id}>Enregistrer</button></>}>
          {edition.nouveau && (
            <Champ libelle="Niveau" id="c-niv">
              <select id="c-niv" value={edition.niveau_id} onChange={(e) => setEdition({ ...edition, niveau_id: Number(e.target.value) })}>
                <option value="">Choisir…</option>
                {niveaux.donnees?.map((n) => <option key={n.id} value={n.id}>{n.libelle}</option>)}
              </select>
            </Champ>
          )}
          <div className="deux-colonnes">
            <Champ libelle="Interros par période" id="c-i"><input id="c-i" type="number" min="0" max="10" value={edition.nb_interros} onChange={(e) => setEdition({ ...edition, nb_interros: e.target.value })} /></Champ>
            <Champ libelle="Devoirs par période" id="c-d"><input id="c-d" type="number" min="0" max="5" value={edition.nb_devoirs} onChange={(e) => setEdition({ ...edition, nb_devoirs: e.target.value })} /></Champ>
          </div>
        </Fenetre>
      )}
    </div>
  );
}
