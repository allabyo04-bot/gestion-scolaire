import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, Chargement, Alerte, formatDate } from '../composants/commun.jsx';
import ChoixEcole from '../composants/ChoixEcole.jsx';

const ACTIONS = {
  CONNEXION: 'Connexion', ECHEC_CONNEXION: 'Échec de connexion', DECONNEXION: 'Déconnexion',
  SAISIE_NOTES: 'Saisie de notes', VALIDATION: 'Validation de notes', CORRECTION_NOTE: 'Correction de note',
  REOUVERTURE: 'Réouverture', GENERATION_EVALUATIONS: 'Préparation des évaluations',
  CREATION: 'Création', SUPPRESSION: 'Suppression', ACTIVATION: 'Activation de compte', DESACTIVATION: 'Désactivation de compte',
  REINITIALISATION_MDP: 'Nouveau mot de passe', CHANGEMENT_MDP: 'Changement de mot de passe',
};
const SENSIBLES = ['ECHEC_CONNEXION', 'CORRECTION_NOTE', 'REOUVERTURE', 'SUPPRESSION', 'DESACTIVATION'];

function Valeurs({ avant, apres }) {
  if (!avant && !apres) return null;
  const a = avant ? JSON.parse(avant) : {}; const b = apres ? JSON.parse(apres) : {};
  const cles = [...new Set([...Object.keys(a), ...Object.keys(b)])];
  return <span className="valeurs">{cles.map((k) => <span key={k}>{k} : {String(a[k] ?? '—')} ⟶ <strong>{String(b[k] ?? '—')}</strong></span>)}</span>;
}

export default function Journal() {
  const { utilisateur } = useSession();
  const [ecoleId, setEcoleId] = useState(null);
  const [filtres, setFiltres] = useState({ action: '', du: '', au: '' });
  const j = useDonnees(() => api.get('journal/liste', { ...filtres, ecole_id: ecoleId, limite: 200 }), [ecoleId, filtres.action, filtres.du, filtres.au]);
  const maj = (k) => (e) => setFiltres({ ...filtres, [k]: e.target.value });

  return (
    <section>
      <div className="entete-page"><h1>Journal des actions</h1><p>Qui a fait quoi, et quand. Les 200 dernières actions correspondant aux filtres.</p></div>
      <div className="filtres">
        {utilisateur.role === 'SUPER_ADMIN' && <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} toutes />}
        <label><span>Action</span>
          <select value={filtres.action} onChange={maj('action')}>
            <option value="">Toutes</option>
            {Object.entries(ACTIONS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        <label><span>Du</span><input type="date" value={filtres.du} onChange={maj('du')} /></label>
        <label><span>Au</span><input type="date" value={filtres.au} onChange={maj('au')} /></label>
      </div>
      {j.charge && <Chargement />}
      {j.erreur && <Alerte>{j.erreur}</Alerte>}
      {j.donnees?.length === 0 && <div className="vide"><p>Aucune action ne correspond à ces filtres.</p></div>}
      <ol className="journal">
        {j.donnees?.map((l) => (
          <li key={l.id} className={SENSIBLES.includes(l.action) ? 'sensible' : ''}>
            <time dateTime={l.cree_le}>{formatDate(l.cree_le)}</time>
            <div>
              <p><strong>{l.utilisateur?.trim() || l.identifiant || 'Inconnu'}</strong> : {ACTIONS[l.action] ?? l.action}</p>
              {l.description && <p className="discret">{l.description}</p>}
              <Valeurs avant={l.valeurs_avant} apres={l.valeurs_apres} />
            </div>
          </li>
        ))}
      </ol>
    </section>
  );
}
