import { api } from '../api.js';
import { useDonnees, Chargement, Alerte } from '../composants/commun.jsx';
import { fcfa, enLettresFCFA } from '../composants/montants.js';
import { MODES } from './Caisse.jsx';

const dateLongue = (d) => new Date(d.replace(' ', 'T')).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

function Exemplaire({ d, libelle }) {
  const { paiement: p, inscription: i, ecole: e } = d;
  return (
    <article className="recu">
      <header className="recu-entete">
        {e.images?.LOGO && <img className="doc-logo" src={e.images.LOGO} alt="" />}
        <div className="recu-officiel">
          {[e.entete_ligne1, e.entete_ligne2, e.entete_ligne3].filter(Boolean).map((l) => <p key={l}>{l}</p>)}
        </div>
        <div className="recu-ecole">
          <strong>{e.nom_officiel}</strong>
          <p>{[e.adresse, e.ville].filter(Boolean).join(', ')}</p>
          {e.telephone && <p>Tél. {e.telephone}</p>}
        </div>
      </header>
      <div className="recu-titre">
        <h1>Reçu de paiement</h1>
        <div className="recu-numero"><span>N°</span> {p.numero_recu}</div>
      </div>
      <dl className="recu-lignes">
        <div><dt>Élève</dt><dd><strong>{i.nom} {i.prenoms}</strong></dd></div>
        <div><dt>Matricule</dt><dd>{i.matricule}{i.educmaster ? `, Educmaster ${i.educmaster}` : ''}</dd></div>
        <div><dt>Classe</dt><dd>{i.classe}, année scolaire {i.annee}</dd></div>
        <div><dt>Objet</dt><dd>Frais de scolarité</dd></div>
        {p.verse_par && <div><dt>Versé par</dt><dd>{p.verse_par}</dd></div>}
        <div><dt>Mode</dt><dd>{MODES[p.mode]}{p.reference ? `, référence ${p.reference}` : ''}</dd></div>
      </dl>
      <div className="recu-montant">
        <span className="recu-somme">{fcfa(p.montant)}</span>
        <span className="recu-lettres">{enLettresFCFA(p.montant)}</span>
      </div>
      <div className="recu-pied">
        <p className="recu-reste">Reste à payer après ce versement : <strong>{d.reste_apres === 0 ? 'scolarité soldée' : fcfa(d.reste_apres)}</strong></p>
        <div className="recu-signature">
          <p>{e.ville}, le {dateLongue(p.date_paiement)}</p>
          <p>Le caissier : {p.caissier}</p>
          <div className="recu-cachet">Signature et cachet</div>
        </div>
      </div>
      <p className="recu-exemplaire">{libelle}</p>
    </article>
  );
}

export default function Recu({ paiementId }) {
  const r = useDonnees(() => api.get('fin/recu', { id: paiementId }), [paiementId]);
  if (r.charge) return <Chargement />;
  if (r.erreur) return <Alerte>{r.erreur}</Alerte>;
  const p = r.donnees.paiement;
  return (
    <section>
      <div className="pas-imprimer barre-recu">
        <a href={`#/caisse/encaisser/${p.inscription_id}`} className="retour">Situation de l'élève</a>
        <button className="bouton bouton-principal" onClick={() => window.print()}>Imprimer le reçu</button>
      </div>
      {Number(p.annule) ? <Alerte>Ce paiement a été annulé : {p.motif_annulation}. Le reçu n'est plus valable.</Alerte> : null}
      <div className="feuille-recus">
        <Exemplaire d={r.donnees} libelle="Exemplaire du parent" />
        <div className="ligne-decoupe" aria-hidden="true"><span>✂</span></div>
        <Exemplaire d={r.donnees} libelle="Exemplaire de l'école" />
      </div>
    </section>
  );
}
