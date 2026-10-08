import { useState } from 'react';
import { api } from '../api.js';
import { Alerte, Champ, useMessage } from '../composants/commun.jsx';

export default function ChangerMdp({ obligatoire, surTermine }) {
  const message = useMessage();
  const [f, setF] = useState({ ancien: '', nouveau: '', confirmation: '' });
  const [erreur, setErreur] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value });

  const envoyer = async (e) => {
    e.preventDefault();
    setErreur('');
    if (f.nouveau !== f.confirmation) { setErreur('Les deux nouveaux mots de passe ne sont pas identiques.'); return; }
    setEnvoi(true);
    try {
      await api.post('auth/changer_mdp', { ancien_mot_de_passe: f.ancien, nouveau_mot_de_passe: f.nouveau });
      message('Mot de passe modifié.');
      surTermine();
    } catch (err) { setErreur(err.message); setEnvoi(false); }
  };

  const formulaire = (
    <form className={obligatoire ? 'carte-connexion' : 'formulaire-etroit'} onSubmit={envoyer}>
      <h1>{obligatoire ? 'Choisissez votre mot de passe' : 'Changer mon mot de passe'}</h1>
      {obligatoire && <p className="sous-titre">Le mot de passe reçu de la direction est provisoire. Remplacez-le par un mot de passe personnel.</p>}
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle={obligatoire ? 'Mot de passe provisoire' : 'Mot de passe actuel'} id="ancien">
        <input id="ancien" type="password" autoComplete="current-password" required value={f.ancien} onChange={maj('ancien')} />
      </Champ>
      <Champ libelle="Nouveau mot de passe" id="nouveau" aide="Au moins 8 caractères, avec des lettres et des chiffres.">
        <input id="nouveau" type="password" autoComplete="new-password" required minLength={8} value={f.nouveau} onChange={maj('nouveau')} />
      </Champ>
      <Champ libelle="Confirmer le nouveau mot de passe" id="confirmation">
        <input id="confirmation" type="password" autoComplete="new-password" required value={f.confirmation} onChange={maj('confirmation')} />
      </Champ>
      <button className="bouton bouton-principal bouton-large" disabled={envoi}>{envoi ? 'Enregistrement…' : 'Enregistrer le mot de passe'}</button>
    </form>
  );
  return obligatoire ? <div className="page-connexion">{formulaire}</div> : formulaire;
}
