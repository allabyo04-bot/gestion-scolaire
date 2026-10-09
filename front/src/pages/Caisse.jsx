import { useEffect, useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, estDirection, aller, formatDate } from '../composants/commun.jsx';
import ChoixEcole from '../composants/ChoixEcole.jsx';
import { fcfa } from '../composants/montants.js';
import RemiseCaisse from './RemiseCaisse.jsx';

export const MODES = { ESPECES: 'Espèces', MOBILE_MONEY: 'Mobile money', VIREMENT: 'Virement', CHEQUE: 'Chèque' };
const ETATS = { PAYEE: ['Payée', 'statut-nouveau'], PARTIELLE: ['Partielle', 'statut-reinscription'], A_VENIR: ['À venir', 'statut-ignore'], EN_RETARD: ['En retard', 'statut-erreur'] };
const dateFr = (d) => d ? d.split('-').reverse().join('/') : '—';
const aujourdhui = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

export default function Caisse({ vue = 'encaisser', inscriptionId }) {
  const { utilisateur } = useSession();
  const [ecoleId, setEcoleId] = useState(utilisateur.ecole?.id ?? null);
  return (
    <section>
      <div className="entete-page"><h1>Caisse</h1><p>Scolarité : encaissements, reçus, journal et impayés.</p></div>
      <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} />
      <nav className="sous-onglets" aria-label="Caisse">
        {[['encaisser', 'Encaisser'], ['remise', 'Remise de caisse'], ['journal', 'Journal de caisse'], ['impayes', 'Impayés']].map(([id, lib]) =>
          <a key={id} href={`#/caisse/${id}`} aria-current={vue === id ? 'page' : undefined}>{lib}</a>)}
      </nav>
      {ecoleId && vue === 'encaisser' && <Encaisser key={ecoleId} ecoleId={ecoleId} inscriptionId={inscriptionId} />}
      {ecoleId && vue === 'remise' && <RemiseCaisse key={ecoleId} ecoleId={ecoleId} />}
      {ecoleId && vue === 'journal' && <JournalCaisse key={ecoleId} ecoleId={ecoleId} />}
      {ecoleId && vue === 'impayes' && <Impayes key={ecoleId} ecoleId={ecoleId} />}
    </section>
  );
}

// ------------------------------------------------------------------ Recherche + situation
function Encaisser({ ecoleId, inscriptionId }) {
  const [q, setQ] = useState('');
  const [terme, setTerme] = useState('');
  useEffect(() => { const t = setTimeout(() => setTerme(q.trim()), 300); return () => clearTimeout(t); }, [q]);
  const r = useDonnees(() => terme.length >= 2 ? api.get('eleves/liste', { q: terme, ecole_id: ecoleId }) : Promise.resolve(null), [terme, ecoleId]);
  if (inscriptionId) return <Situation inscriptionId={inscriptionId} />;
  return (
    <div>
      <input className="recherche" type="search" autoFocus placeholder="Nom, matricule ou numéro Educmaster de l'élève"
             aria-label="Rechercher un élève" value={q} onChange={(e) => setQ(e.target.value)} />
      {terme.length < 2 && <p className="discret">Tapez au moins deux lettres du nom de l'élève.</p>}
      {r.charge && terme.length >= 2 && <Chargement texte="Recherche…" />}
      {r.erreur && <Alerte>{r.erreur}</Alerte>}
      {r.donnees?.length === 0 && <div className="vide"><p>Aucun élève inscrit cette année ne correspond.</p></div>}
      {r.donnees?.length > 0 && (
        <ul className="resultats-recherche">
          {r.donnees.map((e) => (
            <li key={e.id}><a href={`#/caisse/encaisser/${e.inscription_id}`}>
              <strong>{e.nom} {e.prenoms}</strong><span>{e.classe}</span><small className="sous-ligne">{e.matricule}</small>
            </a></li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function Situation({ inscriptionId, compacte = false }) {
  const { utilisateur } = useSession();
  const direction = estDirection(utilisateur);
  const message = useMessage();
  const s = useDonnees(() => api.get('fin/situation', { inscription_id: inscriptionId }), [inscriptionId]);
  const [fenetre, setFenetre] = useState(null);
  if (s.charge && !s.donnees) return <Chargement />;
  if (s.erreur) return <Alerte>{s.erreur}</Alerte>;
  const { inscription: i, tarif, tranches, remises, paiements, totaux: t } = s.donnees;
  const prochaine = tranches.find((x) => x.reste > 0);
  const fini = () => { setFenetre(null); s.recharger(); };

  const retirerRemise = async (r) => {
    if (!window.confirm(`Retirer la remise de ${fcfa(r.montant)} ?`)) return;
    try { await api.post('fin/remise_supprimer', { id: r.id }); message('Remise retirée.'); s.recharger(); } catch (x) { message(x.message, 'erreur'); }
  };

  return (
    <div className="situation">
      {!compacte && <a href="#/caisse/encaisser" className="retour">Autre élève</a>}
      {!compacte && (
        <div className="entete-avec-action">
          <div>
            <h2 className="titre-section">{i.nom} {i.prenoms}</h2>
            <p className="discret">{i.classe}, {i.matricule}, année {i.annee}{i.statut !== 'ACTIF' ? `, ${i.statut.toLowerCase()}` : ''}</p>
          </div>
          {tarif && t.reste > 0 && i.statut === 'ACTIF' && <button className="bouton bouton-principal" onClick={() => setFenetre('encaisser')}>Encaisser un paiement</button>}
        </div>
      )}
      {!tarif && <Alerte type="info">Aucun tarif n'est défini pour le niveau {i.niveau}. {direction ? 'Fixez-le dans Paramètres > Frais de scolarité.' : 'La direction doit d\'abord le fixer.'}</Alerte>}
      {tarif && (
        <>
          <dl className="statistiques">
            <div><dt>À payer{t.remises ? ' (après remise)' : ''}</dt><dd>{fcfa(t.du)}</dd></div>
            <div><dt>Déjà payé</dt><dd>{fcfa(t.paye)}</dd></div>
            <div className={t.reste === 0 ? 'stat-solde' : ''}><dt>Reste à payer</dt><dd>{t.reste === 0 ? 'Soldé' : fcfa(t.reste)}</dd></div>
            {t.en_retard > 0 && <div className="stat-retard"><dt>En retard</dt><dd>{fcfa(t.en_retard)}</dd></div>}
          </dl>
          {compacte && t.reste > 0 && <a className="bouton" href={`#/caisse/encaisser/${i.id}`}>Ouvrir dans la caisse</a>}
          {!compacte && (
            <>
              <h3 className="titre-sous-section">Tranches</h3>
              <div className="tableau-defilant">
                <table className="tableau">
                  <thead><tr><th scope="col">Tranche</th><th scope="col">Échéance</th><th scope="col" className="nombre">À payer</th><th scope="col" className="nombre">Payé</th><th scope="col">État</th></tr></thead>
                  <tbody>{tranches.map((x) => (
                    <tr key={x.id}><th scope="row">{x.libelle}</th><td>{dateFr(x.echeance)}</td><td className="nombre">{fcfa(x.a_payer)}</td>
                      <td className="nombre">{fcfa(x.paye)}</td><td><span className={ETATS[x.etat][1]}>{ETATS[x.etat][0]}</span></td></tr>))}
                  </tbody>
                </table>
              </div>
              <h3 className="titre-sous-section">Paiements</h3>
              {paiements.length === 0 ? <p className="discret">Aucun paiement pour l'instant.</p> : (
                <div className="tableau-defilant">
                  <table className="tableau">
                    <thead><tr><th scope="col">Reçu</th><th scope="col">Date</th><th scope="col" className="nombre">Montant</th><th scope="col">Mode</th><th scope="col"><span className="visuellement-cache">Actions</span></th></tr></thead>
                    <tbody>{paiements.map((p) => (
                      <tr key={p.id} className={Number(p.annule) ? 'inactif' : ''}>
                        <th scope="row" className="chiffres">{p.numero_recu}{Number(p.annule) ? <small className="note-ligne">Annulé : {p.motif_annulation}</small> : <small className="note-ligne">par {p.caissier}</small>}</th>
                        <td>{dateFr(p.date_paiement)}</td>
                        <td className="nombre">{Number(p.annule) ? <s>{fcfa(p.montant)}</s> : fcfa(p.montant)}</td>
                        <td>{MODES[p.mode]}{p.reference ? <small className="note-ligne">{p.reference}</small> : null}</td>
                        <td className="actions-ligne">
                          {!Number(p.annule) && <a href={`#/recu/${p.id}`}>Reçu</a>}
                          {!Number(p.annule) && direction && <button className="bouton-lien" onClick={() => setFenetre({ annuler: p })}>Annuler</button>}
                        </td>
                      </tr>))}
                    </tbody>
                  </table>
                </div>
              )}
              <div className="entete-avec-action">
                <h3 className="titre-sous-section">Remises</h3>
                {direction && t.reste > 0 && <button className="bouton bouton-discret" onClick={() => setFenetre('remise')}>Accorder une remise</button>}
              </div>
              {remises.length === 0 ? <p className="discret">Aucune remise.</p> : (
                <ul className="liste-simple">{remises.map((r) => (
                  <li key={r.id}><strong>{fcfa(r.montant)}</strong> {r.motif} <small className="discret">par {r.auteur}, le {formatDate(r.accordee_le)}</small>
                    {direction && <button className="bouton-lien" onClick={() => retirerRemise(r)}>Retirer</button>}</li>))}
                </ul>
              )}
            </>
          )}
        </>
      )}
      {fenetre === 'encaisser' && <FenetreEncaisser inscription={i} reste={t.reste} suggestion={prochaine?.reste} surFermer={() => setFenetre(null)}
        surFait={(r) => { setFenetre(null); message(`Paiement enregistré. Reçu ${r.numero_recu}.`); aller('recu', r.id); }} />}
      {fenetre === 'remise' && <FenetreRemise inscription={i} reste={t.reste} surFermer={() => setFenetre(null)} surFait={fini} />}
      {fenetre?.annuler && <FenetreAnnulation paiement={fenetre.annuler} surFermer={() => setFenetre(null)} surFait={fini} />}
    </div>
  );
}

function FenetreEncaisser({ inscription, reste, suggestion, surFermer, surFait }) {
  const [f, setF] = useState({ montant: String(suggestion ?? reste), mode: 'ESPECES', reference: '', verse_par: '', date_paiement: aujourdhui() });
  const [erreur, setErreur] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const montant = Number(String(f.montant).replace(/\s/g, ''));
  const envoyer = async () => {
    setErreur(''); setEnvoi(true);
    try { surFait(await api.post('fin/encaisser', { ...f, inscription_id: inscription.id })); }
    catch (x) { setErreur(x.message); setEnvoi(false); }
  };
  return (
    <Fenetre titre={`Encaisser : ${inscription.nom} ${inscription.prenoms}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer} disabled={envoi || !montant}>{envoi ? 'Enregistrement…' : `Encaisser ${montant ? fcfa(montant) : ''}`}</button></>}>
      <p className="discret">Reste à payer : <strong>{fcfa(reste)}</strong>{suggestion && suggestion !== reste ? `, dont ${fcfa(suggestion)} pour la tranche en cours` : ''}.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <div className="deux-colonnes">
        <Champ libelle="Montant (F CFA)" id="e-mt"><input id="e-mt" inputMode="numeric" value={f.montant} onChange={maj('montant')} /></Champ>
        <Champ libelle="Mode de paiement" id="e-mode">
          <select id="e-mode" value={f.mode} onChange={maj('mode')}>{Object.entries(MODES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
        </Champ>
      </div>
      {f.mode !== 'ESPECES' && (
        <Champ libelle={f.mode === 'CHEQUE' ? 'Numéro du chèque' : 'Référence de la transaction'} id="e-ref">
          <input id="e-ref" value={f.reference} onChange={maj('reference')} />
        </Champ>
      )}
      <div className="deux-colonnes">
        <Champ libelle="Versé par (facultatif)" id="e-par"><input id="e-par" placeholder="Nom du parent ou tuteur" value={f.verse_par} onChange={maj('verse_par')} /></Champ>
        <Champ libelle="Date du paiement" id="e-date"><input id="e-date" type="date" max={aujourdhui()} value={f.date_paiement} onChange={maj('date_paiement')} /></Champ>
      </div>
    </Fenetre>
  );
}

function FenetreRemise({ inscription, reste, surFermer, surFait }) {
  const message = useMessage();
  const [f, setF] = useState({ montant: '', motif: '' });
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    try { await api.post('fin/remise', { ...f, inscription_id: inscription.id }); message('Remise accordée.'); surFait(); } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre="Accorder une remise" surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button><button className="bouton bouton-principal" onClick={envoyer}>Accorder</button></>}>
      <p className="discret">La remise réduit le montant à payer pour l'année (au plus {fcfa(reste)}). Elle est tracée dans le journal.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Montant (F CFA)" id="r-mt"><input id="r-mt" inputMode="numeric" value={f.montant} onChange={(e) => setF({ ...f, montant: e.target.value })} /></Champ>
      <Champ libelle="Motif" id="r-mo" aide="Exemple : 2e enfant de la fratrie, enfant du personnel."><input id="r-mo" value={f.motif} onChange={(e) => setF({ ...f, motif: e.target.value })} /></Champ>
    </Fenetre>
  );
}

function FenetreAnnulation({ paiement, surFermer, surFait }) {
  const message = useMessage();
  const [motif, setMotif] = useState('');
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    try { await api.post('fin/annuler', { id: paiement.id, motif }); message(`Reçu ${paiement.numero_recu} annulé.`); surFait(); } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={`Annuler le reçu ${paiement.numero_recu}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Retour</button><button className="bouton bouton-danger" onClick={envoyer}>Annuler ce paiement</button></>}>
      <p>Le paiement de <strong>{fcfa(paiement.montant)}</strong> ne sera plus compté. Il reste visible, barré, avec le motif, dans la fiche et le journal.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      <Champ libelle="Motif de l'annulation" id="a-mo"><textarea id="a-mo" rows={2} value={motif} onChange={(e) => setMotif(e.target.value)} /></Champ>
    </Fenetre>
  );
}

// ------------------------------------------------------------------ Journal de caisse
function JournalCaisse({ ecoleId }) {
  const { utilisateur } = useSession();
  const direction = estDirection(utilisateur);
  const [f, setF] = useState({ du: aujourdhui(), au: aujourdhui(), caissier_id: '' });
  const j = useDonnees(() => api.get('fin/journal', { ...f, ecole_id: ecoleId }), [ecoleId, f.du, f.au, f.caissier_id]);
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <div>
      <div className="filtres pas-imprimer">
        <label><span>Du</span><input type="date" value={f.du} onChange={maj('du')} /></label>
        <label><span>Au</span><input type="date" value={f.au} onChange={maj('au')} /></label>
        {direction && j.donnees?.caissiers.length > 0 && (
          <label><span>Caissier</span>
            <select value={f.caissier_id} onChange={maj('caissier_id')}><option value="">Tous</option>
              {j.donnees.caissiers.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}</select>
          </label>
        )}
      </div>
      {!direction && <p className="discret pas-imprimer">Vous voyez les paiements que vous avez encaissés.</p>}
      {j.charge && !j.donnees && <Chargement />}
      {j.erreur && <Alerte>{j.erreur}</Alerte>}
      {j.donnees && (
        <>
          <h2 className="seulement-imprimer">Journal de caisse du {dateFr(j.donnees.du)} au {dateFr(j.donnees.au)}</h2>
          <dl className="statistiques">
            <div><dt>Total encaissé</dt><dd>{fcfa(j.donnees.total)}</dd></div>
            {Object.entries(j.donnees.par_mode).filter(([, v]) => v > 0).map(([k, v]) => <div key={k}><dt>{MODES[k]}</dt><dd>{fcfa(v)}</dd></div>)}
          </dl>
          {j.donnees.paiements.length === 0 ? <div className="vide"><p>Aucun paiement sur cette période.</p></div> : (
            <>
              <div className="tableau-defilant">
                <table className="tableau">
                  <thead><tr><th scope="col">Reçu</th><th scope="col">Élève</th><th scope="col" className="nombre">Montant</th><th scope="col">Mode</th><th scope="col">Caissier</th></tr></thead>
                  <tbody>{j.donnees.paiements.map((p) => (
                    <tr key={p.id} className={Number(p.annule) ? 'inactif' : ''}>
                      <th scope="row" className="chiffres"><a href={`#/recu/${p.id}`}>{p.numero_recu}</a><small className="note-ligne">{dateFr(p.date_paiement)}</small></th>
                      <td>{p.nom} {p.prenoms}<small className="note-ligne">{p.classe}</small></td>
                      <td className="nombre">{Number(p.annule) ? <><s>{fcfa(p.montant)}</s><small className="note-ligne">Annulé</small></> : fcfa(p.montant)}</td>
                      <td>{MODES[p.mode]}</td><td>{p.caissier}</td>
                    </tr>))}
                  </tbody>
                </table>
              </div>
              <button className="bouton pas-imprimer" onClick={() => window.print()}>Imprimer le journal</button>
            </>
          )}
        </>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ Impayés
function Impayes({ ecoleId }) {
  const classes = useDonnees(() => api.get('ref/classes', { ecole_id: ecoleId }), [ecoleId]);
  const [classeId, setClasseId] = useState('');
  const [retardSeul, setRetardSeul] = useState(false);
  const r = useDonnees(() => api.get('fin/impayes', { ecole_id: ecoleId, classe_id: classeId }), [ecoleId, classeId]);
  const liste = (r.donnees?.eleves ?? []).filter((e) => !retardSeul || e.en_retard > 0);
  const classe = classes.donnees?.find((c) => String(c.id) === String(classeId));
  return (
    <div>
      <div className="filtres pas-imprimer">
        <label><span>Classe</span>
          <select value={classeId} onChange={(e) => setClasseId(e.target.value)}><option value="">Toute l'école</option>
            {classes.donnees?.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}</select>
        </label>
        <label className="case case-alignee"><input type="checkbox" checked={retardSeul} onChange={(e) => setRetardSeul(e.target.checked)} /> Seulement les retards</label>
      </div>
      {r.charge && !r.donnees && <Chargement texte="Calcul des impayés…" />}
      {r.erreur && <Alerte>{r.erreur}</Alerte>}
      {r.donnees && (
        <>
          <h2 className="seulement-imprimer">Impayés {classe ? `de la ${classe.nom}` : "de l'école"}, au {dateFr(aujourdhui())}</h2>
          <dl className="statistiques">
            <div><dt>Attendu</dt><dd>{fcfa(r.donnees.totaux.du)}</dd></div>
            <div><dt>Encaissé</dt><dd>{fcfa(r.donnees.totaux.paye)}</dd></div>
            <div><dt>Reste à recouvrer</dt><dd>{fcfa(r.donnees.totaux.reste)}</dd></div>
            <div className={r.donnees.totaux.en_retard ? 'stat-retard' : ''}><dt>Dont en retard</dt><dd>{fcfa(r.donnees.totaux.en_retard)}</dd></div>
          </dl>
          {r.donnees.totaux.sans_tarif > 0 && <Alerte type="info">{r.donnees.totaux.sans_tarif} élève(s) ne sont pas comptés : leur niveau n'a pas de tarif.</Alerte>}
          {liste.length === 0 ? <div className="vide"><p>{retardSeul ? 'Aucun retard de paiement.' : 'Tous les élèves sont à jour.'}</p></div> : (
            <>
              <div className="tableau-defilant">
                <table className="tableau">
                  <thead><tr><th scope="col">Élève</th>{!classeId && <th scope="col">Classe</th>}<th scope="col" className="nombre">Payé</th><th scope="col" className="nombre">Reste</th><th scope="col" className="nombre">En retard</th><th scope="col">Tuteur</th></tr></thead>
                  <tbody>{liste.map((e) => (
                    <tr key={e.inscription_id}>
                      <th scope="row"><a href={`#/caisse/encaisser/${e.inscription_id}`}>{e.nom} {e.prenoms}</a></th>
                      {!classeId && <td>{e.classe}</td>}
                      <td className="nombre">{fcfa(e.paye)}</td><td className="nombre">{fcfa(e.reste)}</td>
                      <td className={`nombre${e.en_retard ? ' texte-retard' : ''}`}>{e.en_retard ? fcfa(e.en_retard) : '—'}</td>
                      <td>{e.telephone ? <a href={`tel:${e.telephone}`}>{e.telephone}</a> : <span className="discret">—</span>}</td>
                    </tr>))}
                  </tbody>
                </table>
              </div>
              <button className="bouton pas-imprimer" onClick={() => window.print()}>Imprimer la liste</button>
            </>
          )}
        </>
      )}
    </div>
  );
}
