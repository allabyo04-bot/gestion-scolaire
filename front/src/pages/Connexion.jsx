import { useState } from 'react';
import { api } from '../api.js';
import { Alerte, Champ } from '../composants/commun.jsx';

export default function Connexion({ surConnexion }) {
  const [identifiant, setIdentifiant] = useState('');
  const [mdp, setMdp] = useState('');
  const [erreur, setErreur] = useState('');
  const [envoi, setEnvoi] = useState(false);

  const envoyer = async (e) => {
    e.preventDefault();
    setErreur(''); setEnvoi(true);
    try { surConnexion(await api.post('auth/connexion', { identifiant: identifiant.trim().toLowerCase(), mot_de_passe: mdp })); }
    catch (err) { setErreur(err.message); setEnvoi(false); }
  };

  return (
    <div className="page-connexion">
      <form className="carte-connexion" onSubmit={envoyer}>
        <h1>Réseau des écoles FVPT</h1>
        <p className="sous-titre">Notes, bulletins et suivi des élèves</p>
        {erreur && <Alerte>{erreur}</Alerte>}
        <Champ libelle="Identifiant" id="identifiant">
          <input id="identifiant" autoComplete="username" autoCapitalize="none" required
                 value={identifiant} onChange={(e) => setIdentifiant(e.target.value)} />
        </Champ>
        <Champ libelle="Mot de passe" id="mdp">
          <input id="mdp" type="password" autoComplete="current-password" required
                 value={mdp} onChange={(e) => setMdp(e.target.value)} />
        </Champ>
        <button className="bouton bouton-principal bouton-large" disabled={envoi}>
          {envoi ? 'Connexion…' : 'Se connecter'}
        </button>
        <p className="note-bas">Identifiants perdus ? Adressez-vous à la direction de votre école.</p>
      </form>
    </div>
  );
}
