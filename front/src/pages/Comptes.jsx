import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, ROLES, formatDate } from '../composants/commun.jsx';
import ChoixEcole from '../composants/ChoixEcole.jsx';

export default function Comptes() {
  const { utilisateur } = useSession();
  const message = useMessage();
  const superAdmin = utilisateur.role === 'SUPER_ADMIN';
  const [ecoleId, setEcoleId] = useState(null);
  const comptes = useDonnees(() => api.get('utilisateurs/liste', { ecole_id: ecoleId }), [ecoleId]);
  const [creer, setCreer] = useState(false);
  const [provisoire, setProvisoire] = useState(null);

  const activer = async (c) => {
    try { await api.post('utilisateurs/activer', { id: c.id, actif: c.actif ? 0 : 1 }); message(c.actif ? 'Compte désactivé.' : 'Compte réactivé.'); comptes.recharger(); }
    catch (e) { message(e.message, 'erreur'); }
  };
  const reinitialiser = async (c) => {
    if (!window.confirm(`Générer un nouveau mot de passe provisoire pour ${c.prenoms} ${c.nom} ?`)) return;
    try { setProvisoire(await api.post('utilisateurs/reinitialiser_mdp', { id: c.id })); }
    catch (e) { message(e.message, 'erreur'); }
  };

  return (
    <section>
      <div className="entete-page entete-avec-action">
        <div><h1>Comptes</h1><p>Chaque personne a son propre compte. Ses actions sont enregistrées dans le journal.</p></div>
        <button className="bouton bouton-principal" onClick={() => setCreer(true)}>Créer un compte</button>
      </div>
      <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} toutes />
      {comptes.charge && <Chargement />}
      {comptes.erreur && <Alerte>{comptes.erreur}</Alerte>}
      {comptes.donnees && (
        <div className="tableau-defilant">
          <table className="tableau">
            <thead><tr><th scope="col">Nom</th><th scope="col">Rôle</th>{superAdmin && <th scope="col">École</th>}<th scope="col">Identifiant</th><th scope="col">Dernière connexion</th><th scope="col"><span className="visuellement-cache">Actions</span></th></tr></thead>
            <tbody>
              {comptes.donnees.map((c) => (
                <tr key={c.id} className={c.actif ? '' : 'inactif'}>
                  <th scope="row">{c.nom} {c.prenoms}{!c.actif && <small className="etiquette">Désactivé</small>}</th>
                  <td>{ROLES[c.role]}</td>
                  {superAdmin && <td>{c.ecole ?? 'Toutes'}</td>}
                  <td><code>{c.identifiant}</code></td>
                  <td>{c.derniere_connexion ? formatDate(c.derniere_connexion) : 'Jamais'}</td>
                  <td className="actions-ligne">
                    {c.id !== utilisateur.id && (superAdmin || ['SECRETARIAT', 'COMPTABLE', 'PROFESSEUR'].includes(c.role)) && <>
                      <button className="bouton-lien" onClick={() => reinitialiser(c)}>Nouveau mot de passe</button>
                      <button className="bouton-lien" onClick={() => activer(c)}>{c.actif ? 'Désactiver' : 'Réactiver'}</button>
                    </>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {creer && <FenetreCreation superAdmin={superAdmin} surFermer={() => setCreer(false)}
        surCree={(r) => { setCreer(false); setProvisoire(r); comptes.recharger(); }} />}
      {provisoire && (
        <Fenetre titre="Mot de passe provisoire" surFermer={() => setProvisoire(null)}
                 actions={<button className="bouton bouton-principal" onClick={() => setProvisoire(null)}>J'ai noté le mot de passe</button>}>
          <p>Communiquez ces informations à la personne. Le mot de passe ne sera plus affiché ensuite ; elle devra le changer à sa première connexion.</p>
          <dl className="identifiants">
            <div><dt>Identifiant</dt><dd><code>{provisoire.identifiant}</code></dd></div>
            <div><dt>Mot de passe provisoire</dt><dd><code>{provisoire.mot_de_passe_provisoire}</code></dd></div>
          </dl>
        </Fenetre>
      )}
    </section>
  );
}

function FenetreCreation({ superAdmin, surFermer, surCree }) {
  const roles = superAdmin ? ['PROFESSEUR', 'SECRETARIAT', 'COMPTABLE', 'DIRECTRICE', 'SUPER_ADMIN'] : ['PROFESSEUR', 'SECRETARIAT', 'COMPTABLE'];
  const [f, setF] = useState({ role: 'PROFESSEUR', nom: '', prenoms: '', identifiant: '', telephone: '', email: '' });
  const [ecoleId, setEcoleId] = useState(null);
  const [erreur, setErreur] = useState('');
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const proposer = () => {
    if (f.identifiant || !f.nom || !f.prenoms) return;
    const s = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
    setF((x) => ({ ...x, identifiant: `${s(x.prenoms.split(' ')[0])}.${s(x.nom)}` }));
  };
  const envoyer = async () => {
    setErreur('');
    try { surCree(await api.post('utilisateurs/creer', { ...f, ecole_id: ecoleId })); }
    catch (e) { setErreur(e.message); }
  };
  return (
    <Fenetre titre="Créer un compte" surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer}>Créer le compte</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Rôle" id="f-role">
        <select id="f-role" value={f.role} onChange={maj('role')}>{roles.map((r) => <option key={r} value={r}>{ROLES[r]}</option>)}</select>
      </Champ>
      {superAdmin && f.role !== 'SUPER_ADMIN' && <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} />}
      <div className="deux-colonnes">
        <Champ libelle="Nom" id="f-nom"><input id="f-nom" value={f.nom} onChange={maj('nom')} onBlur={proposer} /></Champ>
        <Champ libelle="Prénoms" id="f-prenoms"><input id="f-prenoms" value={f.prenoms} onChange={maj('prenoms')} onBlur={proposer} /></Champ>
      </div>
      <Champ libelle="Identifiant de connexion" id="f-id" aide="Proposé automatiquement, modifiable. Lettres, chiffres et points.">
        <input id="f-id" autoCapitalize="none" value={f.identifiant} onChange={maj('identifiant')} />
      </Champ>
      <div className="deux-colonnes">
        <Champ libelle="Téléphone (facultatif)" id="f-tel"><input id="f-tel" type="tel" value={f.telephone} onChange={maj('telephone')} /></Champ>
        <Champ libelle="E-mail (facultatif)" id="f-mail"><input id="f-mail" type="email" value={f.email} onChange={maj('email')} /></Champ>
      </div>
    </Fenetre>
  );
}
