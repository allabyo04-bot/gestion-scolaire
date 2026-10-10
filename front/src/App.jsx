import { useEffect, useState } from 'react';
import { api, jeton, quandSessionExpire } from './api.js';
import { Session, GroupeActif, ZoneMessages, useRoute, aller, ROLES, estDirection, Chargement } from './composants/commun.jsx';
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
import Caisse from './pages/Caisse.jsx';
import Recu from './pages/Recu.jsx';
import TableauBord from './pages/TableauBord.jsx';
import Signature from './composants/Signature.jsx';
import Absences from './pages/Absences.jsx';
import Bulletins from './pages/Bulletins.jsx';
import { ListeClasse, FicheAppel, Certificat } from './pages/Documents.jsx';

export default function App() {
  const [utilisateur, setUtilisateur] = useState(null);
  const [verifie, setVerifie] = useState(false);

  useEffect(() => {
    quandSessionExpire(() => { jeton.ecrire(null); setUtilisateur(null); });
    if (!jeton.lire()) { setVerifie(true); return; }
    api.get('auth/moi').then(setUtilisateur).catch(() => jeton.ecrire(null)).finally(() => setVerifie(true));
  }, []);

  const connecte = (donnees) => { jeton.ecrire(donnees.jeton); setUtilisateur(donnees.utilisateur); window.location.hash = '#/'; };
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

const CLE_GROUPE = 'gs_groupe';
// Couleurs du groupe : la couleur principale remplace le bleu, l'accent souligne le bandeau
function themeGroupe(g) {
  if (!g?.couleur) return undefined;
  return { '--encre': g.couleur, '--encre-fonce': `color-mix(in srgb, ${g.couleur} 72%, black)`,
           '--encre-pale': `color-mix(in srgb, ${g.couleur} 9%, white)`, '--accent': g.couleur_accent || 'transparent' };
}
function Coquille({ utilisateur, surDeconnexion }) {
  const { page, params } = useRoute();
  const superAdmin = utilisateur.role === 'SUPER_ADMIN';
  const [groupes, setGroupes] = useState([]);
  const [groupeId, setGroupeIdBrut] = useState(() => { try { return Number(localStorage.getItem(CLE_GROUPE)) || null; } catch { return null; } });
  const setGroupeId = (g) => { setGroupeIdBrut(g); try { localStorage.setItem(CLE_GROUPE, String(g)); } catch { /* ignore */ } };
  useEffect(() => {
    api.get('ref/groupes').then((l) => {
      setGroupes(l);
      if (superAdmin && l.length && !l.some((g) => g.id === groupeId)) setGroupeId(l[0].id);
    }).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const groupe = groupes.find((g) => g.id === (superAdmin ? groupeId : utilisateur.ecole?.groupe_id));
  const direction = estDirection(utilisateur);
  const secretariat = utilisateur.role === 'SECRETARIAT';
  const gereEleves = direction || secretariat;
  const comptable = utilisateur.role === 'COMPTABLE';
  const caisse = gereEleves || comptable;
  const onglets = [
    ['tableau', 'Tableau de bord', direction],
    ['accueil', 'Notes', direction || utilisateur.role === 'PROFESSEUR'],
    ['eleves', 'Élèves', gereEleves],
    ['absences', 'Absences', gereEleves || utilisateur.role === 'PROFESSEUR'],
    ['classes', 'Classes', direction || secretariat || utilisateur.role === 'PROFESSEUR'],
    ['caisse', 'Caisse', caisse],
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
    case 'document':
      ecran = !gereEleves ? null : params[0] === 'liste' ? <ListeClasse classeId={params[1]} /> : params[0] === 'appel' ? <FicheAppel classeId={params[1]} />
        : params[0] === 'certificat' ? <Certificat eleveId={params[1]} /> : null; break;
    case 'bulletins': ecran = <Bulletins classeId={params[0]} periodeId={params[1]} />; break;
    case 'absences': ecran = (gereEleves || utilisateur.role === 'PROFESSEUR') ? <Absences vue={params[0]} /> : null; break;
    case 'tableau': ecran = direction ? <TableauBord /> : null; break;
    case 'accueil': ecran = comptable ? <Caisse /> : secretariat ? <Eleves /> : <Accueil />; break;
    case 'caisse': ecran = caisse ? <Caisse vue={params[0]} inscriptionId={params[1]} /> : null; break;
    case 'recu': ecran = caisse ? <Recu paiementId={params[0]} /> : null; break;
    case 'mot-de-passe': ecran = <ChangerMdp surTermine={() => aller('accueil')} />; break;
    default: ecran = direction ? <TableauBord /> : comptable ? <Caisse /> : secretariat ? <Eleves /> : <Accueil />;
  }
  const actif = { saisie: 'accueil', resultats: 'classes', bulletins: 'classes', eleve: 'eleves', recu: 'caisse', document: 'eleves' }[page]
    ?? (page === 'accueil' && comptable ? 'caisse' : page === 'accueil' && secretariat ? 'eleves' : page);
  const ongletActif = ['tableau', 'accueil', 'eleves', 'absences', 'classes', 'caisse', 'comptes', 'parametres', 'journal'].includes(actif) ? actif : direction ? 'tableau' : 'accueil';

  return (
    <GroupeActif.Provider value={{ groupeId: superAdmin ? groupeId : (utilisateur.ecole?.groupe_id ?? null), groupe }}>
    <div className="coquille" key={superAdmin ? `g${groupeId}` : 'g'} style={themeGroupe(groupe)}>
      <header className="bandeau">
        {groupe?.logo && <img className="logo-groupe" src={groupe.logo} alt="" />}
        <div className="bandeau-ecole">
          {superAdmin ? (
            <>
              <select className="choix-groupe" aria-label="Groupe d'écoles" value={groupeId ?? ''} onChange={(e) => setGroupeId(Number(e.target.value))}>
                {groupes.map((g) => <option key={g.id} value={g.id}>{g.nom}</option>)}
              </select>
              <span>{groupe ? `${groupe.nb_ecoles} école${groupe.nb_ecoles > 1 ? 's' : ''}` : ''}</span>
            </>
          ) : (
            <>
              <strong>{utilisateur.ecole?.nom_officiel}</strong>
              <span>{[utilisateur.ecole?.ville, utilisateur.ecole?.groupe].filter(Boolean).join(', ')}</span>
            </>
          )}
        </div>
        <div className="zone-compte">
          <span className="nom-connecte">{utilisateur.prenoms} {utilisateur.nom}<small>{ROLES[utilisateur.role]}</small></span>
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
          <button type="button" className="bouton-deconnexion" onClick={surDeconnexion}>Se déconnecter</button>
        </div>
      </header>
      {onglets.length > 1 && (
        <nav className="onglets" aria-label="Navigation principale">
          {onglets.map(([id, libelle]) => (
            <a key={id} href={`#/${id}`} aria-current={ongletActif === id ? 'page' : undefined}>{libelle}</a>
          ))}
        </nav>
      )}
      <main className="contenu">{ecran}</main>
      <Signature />
    </div>
    </GroupeActif.Provider>
  );
}
