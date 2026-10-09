import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, estDirection, formatDate } from '../composants/commun.jsx';
import { fcfa, enLettresFCFA } from '../composants/montants.js';
import { MODES } from './Caisse.jsx';

const aujourdhui = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const dateLongue = (d) => new Date(d + 'T12:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const ecartTexte = (e) => (e === 0 ? 'Aucun écart' : `${e > 0 ? 'Excédent' : 'Manque'} de ${fcfa(Math.abs(e))}`);

export default function RemiseCaisse({ ecoleId }) {
  const { utilisateur } = useSession();
  const [vue, setVue] = useState(null);     // { caissier_id, date } pour la direction
  if (estDirection(utilisateur) && !vue) return <SuiviDirection ecoleId={ecoleId} surOuvrir={setVue} />;
  return <JourneeCaisse ecoleId={ecoleId} cible={vue} surRetour={vue ? () => setVue(null) : null} />;
}

// ------------------------------------------------------------------ Une journée d'un caissier
function JourneeCaisse({ ecoleId, cible, surRetour }) {
  const { utilisateur } = useSession();
  const message = useMessage();
  const [date, setDate] = useState(cible?.date ?? cible?.date_caisse ?? aujourdhui());
  const j = useDonnees(() => api.get('caisse/jour', { ecole_id: ecoleId, date, caissier_id: cible?.caissier_id }), [ecoleId, date, cible?.caissier_id]);
  const [remettre, setRemettre] = useState(false);
  const [confirmer, setConfirmer] = useState(false);
  const moi = !cible || Number(cible.caissier_id) === Number(utilisateur.id);
  if (j.charge && !j.donnees) return <Chargement />;
  if (j.erreur) return <Alerte>{j.erreur}</Alerte>;
  const d = j.donnees; const r = d.remise;

  return (
    <div>
      <div className="pas-imprimer">
        {surRetour && <button className="retour bouton-lien" onClick={surRetour}>Suivi des remises</button>}
        <div className="filtres">
          <label><span>Journée</span><input type="date" max={aujourdhui()} value={date} onChange={(e) => setDate(e.target.value)} /></label>
        </div>
        {r && <Alerte type="info" action={moi && r.statut !== 'CONFIRMEE' && d.nb_recus > 0 && <button className="bouton bouton-discret" onClick={() => setRemettre(true)}>Corriger la remise</button>}>
          Caisse remise le {formatDate(r.remise_le)}. {r.statut === 'CONFIRMEE' ? `Réception confirmée par ${r.confirmee_par_nom} le ${formatDate(r.confirmee_le)}.` : 'En attente de confirmation par la direction.'}
          {r.changements !== 0 && <strong> Attention : {fcfa(Math.abs(r.changements))} {r.changements > 0 ? 'encaissés' : 'annulés'} après la remise.</strong>}
        </Alerte>}
        {!r && d.nb_recus > 0 && moi && <Alerte type="info" action={<button className="bouton bouton-principal" onClick={() => setRemettre(true)}>Remettre la caisse</button>}>
          Caisse de la journée pas encore remise.</Alerte>}
        {!r && d.nb_recus > 0 && !moi && <Alerte>Ce caissier n'a pas encore fait sa remise pour cette journée.</Alerte>}
        {r && r.statut !== 'CONFIRMEE' && estDirection(utilisateur) && (
          <p><button className="bouton bouton-principal" onClick={() => setConfirmer(true)}>Confirmer la réception</button></p>
        )}
      </div>
      {d.nb_recus === 0 && d.paiements.length === 0 ? <div className="vide"><p>Aucun encaissement le {dateLongue(date)}.</p></div> : <Bordereau d={d} />}
      {d.nb_recus > 0 && <p className="pas-imprimer"><button className="bouton" onClick={() => window.print()}>Imprimer le bordereau</button></p>}
      {remettre && <FenetreRemettre d={d} ecoleId={ecoleId} surFermer={() => setRemettre(false)} surFait={() => { setRemettre(false); message('Caisse remise. Imprimez le bordereau et joignez-le aux espèces.'); j.recharger(); }} />}
      {confirmer && <FenetreConfirmer remise={r} surFermer={() => setConfirmer(false)} surFait={() => { setConfirmer(false); message('Réception confirmée.'); j.recharger(); }} />}
    </div>
  );
}

function Bordereau({ d }) {
  const r = d.remise;
  const lignesModes = Object.entries(d.par_mode).filter(([, v]) => v > 0);
  return (
    <article className="bordereau">
      <header className="bordereau-entete">
        <div className="bordereau-ecole">{d.ecole?.images?.LOGO && <img className="doc-logo" src={d.ecole.images.LOGO} alt="" />}<div><strong>{d.ecole?.nom_officiel}</strong><span>{d.ecole?.ville}</span></div></div>
        <div className="bordereau-titre"><h2>Bordereau de remise de caisse</h2><p>{dateLongue(d.date)}</p></div>
      </header>
      <p>Caissier : <strong>{d.caissier}</strong>. Reçus valides : <strong>{d.nb_recus}</strong>.</p>
      <table className="tableau tableau-bordereau">
        <thead><tr><th scope="col">Reçu</th><th scope="col">Élève</th><th scope="col">Mode</th><th scope="col" className="nombre">Montant</th></tr></thead>
        <tbody>{d.paiements.map((p) => (
          <tr key={p.id} className={Number(p.annule) ? 'inactif' : ''}>
            <td className="chiffres">{p.numero_recu}</td>
            <td>{p.nom} {p.prenoms} <small className="discret">({p.classe})</small>{Number(p.annule) ? <small className="note-ligne">Annulé : {p.motif_annulation}</small> : null}</td>
            <td>{MODES[p.mode]}{p.reference ? ` (${p.reference})` : ''}</td>
            <td className="nombre">{Number(p.annule) ? <s>{fcfa(p.montant)}</s> : fcfa(p.montant)}</td>
          </tr>))}
        </tbody>
        <tfoot>
          {lignesModes.map(([k, v]) => <tr key={k}><th scope="row" colSpan={3}>Total {MODES[k].toLowerCase()}</th><td className="nombre">{fcfa(v)}</td></tr>)}
          <tr className="ligne-total"><th scope="row" colSpan={3}>Total encaissé</th><td className="nombre">{fcfa(d.total)}</td></tr>
        </tfoot>
      </table>
      <div className="bordereau-especes">
        <div><span>Espèces selon le logiciel</span><strong>{fcfa(d.par_mode.ESPECES)}</strong></div>
        <div><span>Espèces remises</span><strong>{r ? fcfa(r.especes_comptees) : '………………'}</strong></div>
        <div className={r && r.ecart !== 0 ? 'ecart-non-nul' : ''}><span>Écart</span><strong>{r ? ecartTexte(r.ecart) : '………………'}</strong></div>
      </div>
      {r && <p className="bordereau-lettres">Espèces remises : {enLettresFCFA(r.especes_comptees)}.{r.commentaire ? ` Observation : ${r.commentaire}` : ''}</p>}
      <div className="bordereau-signatures">
        <div><p>Le caissier</p><p className="signature-nom">{d.caissier}</p></div>
        <div><p>Reçu par la direction</p><p className="signature-nom">{r?.statut === 'CONFIRMEE' ? r.confirmee_par_nom : ''}</p></div>
      </div>
    </article>
  );
}

function FenetreRemettre({ d, ecoleId, surFermer, surFait }) {
  const [especes, setEspeces] = useState(d.remise ? String(d.remise.especes_comptees) : '');
  const [commentaire, setCommentaire] = useState(d.remise?.commentaire ?? '');
  const [erreur, setErreur] = useState('');
  const n = Number(String(especes).replace(/\s/g, ''));
  const ecart = especes === '' ? null : n - d.par_mode.ESPECES;
  const envoyer = async () => {
    try { await api.post('caisse/remettre', { ecole_id: ecoleId, date: d.date, especes_comptees: especes, commentaire }); surFait(); } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre="Remettre la caisse" surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer} disabled={especes === ''}>Valider la remise</button></>}>
      <p>Selon le logiciel, vous avez encaissé <strong>{fcfa(d.par_mode.ESPECES)}</strong> en espèces aujourd'hui ({fcfa(d.total)} tous modes confondus).</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Espèces comptées (F CFA)" id="rc-esp" aide="Comptez les billets et les pièces, puis tapez le total.">
        <input id="rc-esp" inputMode="numeric" autoFocus value={especes} onChange={(e) => setEspeces(e.target.value)} />
      </Champ>
      {ecart !== null && !Number.isNaN(ecart) && <p className={ecart === 0 ? 'texte-ok' : 'texte-retard'}>{ecartTexte(ecart)}</p>}
      {ecart !== null && ecart !== 0 && (
        <Champ libelle="Explication de l'écart" id="rc-com"><textarea id="rc-com" rows={2} value={commentaire} onChange={(e) => setCommentaire(e.target.value)} /></Champ>
      )}
    </Fenetre>
  );
}

function FenetreConfirmer({ remise, surFermer, surFait }) {
  const [commentaire, setCommentaire] = useState('');
  const [erreur, setErreur] = useState('');
  const envoyer = async () => { try { await api.post('caisse/confirmer', { id: remise.id, commentaire }); surFait(); } catch (x) { setErreur(x.message); } };
  return (
    <Fenetre titre="Confirmer la réception" surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button><button className="bouton bouton-principal" onClick={envoyer}>Confirmer</button></>}>
      <p>Vous confirmez avoir reçu <strong>{fcfa(remise.especes_comptees)}</strong> en espèces. La remise ne pourra plus être modifiée.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Commentaire (facultatif)" id="cf-com"><input id="cf-com" value={commentaire} onChange={(e) => setCommentaire(e.target.value)} /></Champ>
    </Fenetre>
  );
}

// ------------------------------------------------------------------ Direction : suivi des remises
function SuiviDirection({ ecoleId, surOuvrir }) {
  const s = useDonnees(() => api.get('caisse/remises', { ecole_id: ecoleId }), [ecoleId]);
  if (s.charge && !s.donnees) return <Chargement />;
  if (s.erreur) return <Alerte>{s.erreur}</Alerte>;
  const { remises, non_remises } = s.donnees;
  const aConfirmer = remises.filter((r) => r.statut === 'REMISE');
  return (
    <div>
      <p className="discret">Les 30 derniers jours. Cliquez sur une ligne pour voir le bordereau.</p>
      {non_remises.length > 0 && (
        <>
          <h3 className="titre-sous-section">Journées encaissées sans remise</h3>
          <ul className="liste-simple">{non_remises.map((n) => (
            <li key={n.date_caisse + n.caissier_id}><button className="bouton-lien" onClick={() => surOuvrir(n)}>{dateLongue(n.date_caisse)}</button>
              <span>{n.caissier} : {n.nb_recus} reçu(s), {fcfa(n.total)}</span><small className="etiquette etiquette-alerte">Non remise</small></li>))}
          </ul>
        </>
      )}
      <h3 className="titre-sous-section">Remises {aConfirmer.length ? `(${aConfirmer.length} à confirmer)` : ''}</h3>
      {remises.length === 0 ? <p className="discret">Aucune remise sur la période.</p> : (
        <div className="tableau-defilant">
          <table className="tableau">
            <thead><tr><th scope="col">Journée</th><th scope="col">Caissier</th><th scope="col" className="nombre">Encaissé</th><th scope="col" className="nombre">Espèces remises</th><th scope="col">Écart</th><th scope="col">État</th></tr></thead>
            <tbody>{remises.map((r) => (
              <tr key={r.id}>
                <th scope="row"><button className="bouton-lien" onClick={() => surOuvrir(r)}>{r.date_caisse.split('-').reverse().join('/')}</button></th>
                <td>{r.caissier}</td><td className="nombre">{fcfa(r.total_systeme)}</td><td className="nombre">{fcfa(r.especes_comptees)}</td>
                <td className={r.ecart ? 'texte-retard' : ''}>{ecartTexte(r.ecart)}{r.changements ? <small className="note-ligne">Modifié après la remise</small> : null}</td>
                <td><span className={r.statut === 'CONFIRMEE' ? 'statut-nouveau' : 'statut-reinscription'}>{r.statut === 'CONFIRMEE' ? 'Confirmée' : 'À confirmer'}</span></td>
              </tr>))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
