import { useEffect, useState } from 'react';
import { api, jeton, quandSessionExpire } from './api.js';
import { Session, ZoneMessages, useRoute, aller, ROLES, estDirection, Chargement } from './composants/commun.jsx';
import Connexion from './pages/Connexion.jsx';
import ChangerMdp from './pages/ChangerMdp.jsx';
import Accueil from './pages/Accueil.jsx';
import SaisieNotes from './pages/SaisieNotes.jsx';
import Classes from './pages/Classes.jsx';
import Resultats from './pages/Resultats.jsx';
import Comptes from './pages/Comptes.jsx';
import Journal from './pages/Journal.jsx';
import Parametres from './pages/Parametres.jsx';
import Eleves from './pages/Eleves.jsx';
import FicheEleve from './pages/FicheEleve.jsx';

export default function App() {
  const [utilisateur, setUtilisateur] = useState(null);
  const [verifie, setVerifie] = useState(false);

  useEffect(() => {
    quandSessionExpire(() => { jeton.ecrire(null); setUtilisateur(null); });
    if (!jeton.lire()) { setVerifie(true); return; }
    api.get('auth/moi').then(setUtilisateur).catch(() => jeton.ecrire(null)).finally(() => setVerifie(true));
  }, []);

  const connecte = (donnees) => { jeton.ecrire(donnees.jeton); setUtilisateur(donnees.utilisateur); aller('accueil'); };
  const deconnecter = async () => {
    try { await api.post('auth/deconnexion'); } catch { /* déjà expirée */ }
    jeton.ecrire(null); setUtilisateur(null); window.location.hash = '';
  };

  let contenu;
  if (!verifie) contenu = <Chargement texte="Ouverture…" />;
  else if (!utilisateur) contenu = <Connexion surConnexion={connecte} />;
  else if (utilisateur.doit_changer_mdp)
    contenu = <ChangerMdp obligatoire surTermine={() => setUtilisateur({ ...utilisateur, doit_changer_mdp: false })} />;
  else contenu = <Coquille utilisateur={utilisateur} surDeconnexion={deconnecter} />;

  return (
    <ZoneMessages>
      <Session.Provider value={{ utilisateur, setUtilisateur }}>{contenu}</Session.Provider>
    </ZoneMessages>
  );
}

function Coquille({ utilisateur, surDeconnexion }) {
  const { page, params } = useRoute();
  const direction = estDirection(utilisateur);
  const secretariat = utilisateur.role === 'SECRETARIAT';
  const gereEleves = direction || secretariat;
  const onglets = [
    ['accueil', 'Notes', direction || utilisateur.role === 'PROFESSEUR'],
    ['eleves', 'Élèves', gereEleves],
    ['classes', 'Classes', direction || secretariat || utilisateur.role === 'PROFESSEUR'],
    ['comptes', 'Comptes', direction],
    ['parametres', 'Paramètres', direction],
    ['journal', 'Journal', direction],
  ].filter((o) => o[2]);

  let ecran;
  switch (page) {
    case 'saisie': ecran = <SaisieNotes classeMatiereId={params[0]} />; break;
    case 'classes': ecran = <Classes />; break;
    case 'resultats': ecran = <Resultats classeId={params[0]} periodeId={params[1]} />; break;
    case 'comptes': ecran = direction ? <Comptes /> : null; break;
    case 'journal': ecran = direction ? <Journal /> : null; break;
    case 'eleves': ecran = gereEleves ? <Eleves classeId={params[0]} /> : null; break;
    case 'eleve': ecran = gereEleves ? <FicheEleve eleveId={params[0]} /> : null; break;
    case 'parametres': ecran = direction ? <Parametres section={params[0]} sousParam={params[1]} /> : null; break;
    case 'mot-de-passe': ecran = <ChangerMdp surTermine={() => aller('accueil')} />; break;
    default: ecran = secretariat ? <Eleves /> : <Accueil />;
  }
  const actif = { saisie: 'accueil', resultats: 'classes', eleve: 'eleves' }[page] ?? (page === 'accueil' && secretariat ? 'eleves' : page);

  return (
    <div className="coquille">
      <header className="bandeau">
        <div className="bandeau-ecole">
          <strong>{utilisateur.ecole?.nom_officiel ?? 'Réseau des écoles'}</strong>
          <span>{utilisateur.ecole?.ville ?? 'Toutes les écoles'}</span>
        </div>
        <details className="menu-compte">
          <summary aria-label="Mon compte">
            <span className="initiales" aria-hidden="true">{utilisateur.prenoms[0]}{utilisateur.nom[0]}</span>
          </summary>
          <div className="menu-compte-panneau">
            <p><strong>{utilisateur.prenoms} {utilisateur.nom}</strong><br />{ROLES[utilisateur.role]}</p>
            <a href="#/mot-de-passe">Changer mon mot de passe</a>
            <button type="button" className="lien" onClick={surDeconnexion}>Se déconnecter</button>
          </div>
        </details>
      </header>
      {onglets.length > 1 && (
        <nav className="onglets" aria-label="Navigation principale">
          {onglets.map(([id, libelle]) => (
            <a key={id} href={`#/${id}`} aria-current={actif === id ? 'page' : undefined}>{libelle}</a>
          ))}
        </nav>
      )}
      <main className="contenu">{ecran}</main>
    </div>
  );
}
