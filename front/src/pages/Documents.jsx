import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, Chargement, Alerte } from '../composants/commun.jsx';

const dateFr = (d) => d ? d.split('-').reverse().join('/') : '';
const dateLongue = (d) => new Date((d ?? new Date().toISOString().slice(0, 10)) + 'T12:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

function EnteteOfficiel({ e, titre, sousTitre }) {
  return (
    <header className="doc-entete">
      <div className="doc-officiel">{[e.entete_ligne1, e.entete_ligne2, e.entete_ligne3].filter(Boolean).map((l) => <p key={l}>{l}</p>)}</div>
      <div className="doc-ecole">
        <strong>{e.nom_officiel}</strong>
        <p>{[e.boite_postale, e.adresse, e.ville].filter(Boolean).join(', ')}</p>
        {e.telephone && <p>Tél. {e.telephone}</p>}
      </div>
      {titre && <div className="doc-titre"><h1>{titre}</h1>{sousTitre && <p>{sousTitre}</p>}</div>}
    </header>
  );
}

function BarreDocument({ retour, libelle }) {
  return (
    <div className="pas-imprimer barre-recu">
      <a href={retour} className="retour">{libelle}</a>
      <button className="bouton bouton-principal" onClick={() => window.print()}>Imprimer</button>
    </div>
  );
}

// ------------------------------------------------------------------ Liste de classe
export function ListeClasse({ classeId }) {
  const d = useDonnees(() => api.get('eleves/liste_classe', { classe_id: classeId }), [classeId]);
  if (d.charge) return <Chargement />;
  if (d.erreur) return <Alerte>{d.erreur}</Alerte>;
  const { ecole, classe, infos, eleves } = d.donnees;
  const f = eleves.filter((x) => x.sexe === 'F').length;
  return (
    <section>
      <BarreDocument retour={`#/eleves/${classeId}`} libelle={`Élèves de la ${classe}`} />
      <article className="document">
        <EnteteOfficiel e={ecole} titre={`Liste des élèves de la ${classe}`} sousTitre={`Année scolaire ${infos.annee}`} />
        <p className="doc-resume">Effectif : <strong>{eleves.length}</strong> ({f} fille{f > 1 ? 's' : ''}, {eleves.length - f} garçon{eleves.length - f > 1 ? 's' : ''}).
          {infos.prof_principal ? ` Professeur principal : ${infos.prof_principal}.` : ''}</p>
        <table className="doc-tableau">
          <thead><tr><th>N°</th><th>Matricule</th><th>Educmaster</th><th>Nom et prénoms</th><th>Sexe</th><th>Né(e) le</th><th>À</th></tr></thead>
          <tbody>{eleves.map((x, i) => (
            <tr key={x.matricule}><td className="c">{i + 1}</td><td className="chiffres">{x.matricule}</td><td className="chiffres">{x.educmaster ?? ''}</td>
              <td><strong>{x.nom}</strong> {x.prenoms}{Number(x.redoublant) ? ' (R)' : ''}</td><td className="c">{x.sexe}</td>
              <td className="chiffres">{dateFr(x.date_naissance)}</td><td>{x.lieu_naissance ?? ''}</td></tr>))}
          </tbody>
        </table>
        <p className="doc-pied">Arrêtée à {eleves.length} élève{eleves.length > 1 ? 's' : ''}. {ecole.ville}, le {dateLongue()}.{eleves.some((x) => Number(x.redoublant)) ? ' (R) : redoublant(e).' : ''}</p>
        <div className="doc-signature"><p>{ecole.titre_signataire}</p><p className="signature-nom">{ecole.nom_directrice}</p></div>
      </article>
    </section>
  );
}

// ------------------------------------------------------------------ Fiche d'appel de la semaine
const lundiDe = (d) => { const x = new Date(d + 'T12:00'); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; };
export function FicheAppel({ classeId }) {
  const d = useDonnees(() => api.get('eleves/liste_classe', { classe_id: classeId }), [classeId]);
  const [semaine, setSemaine] = useState(lundiDe(new Date().toISOString().slice(0, 10)).toISOString().slice(0, 10));
  if (d.charge) return <Chargement />;
  if (d.erreur) return <Alerte>{d.erreur}</Alerte>;
  const { ecole, classe, infos, eleves } = d.donnees;
  const jours = Array.from({ length: 5 }, (_, k) => { const x = lundiDe(semaine); x.setDate(x.getDate() + k); return x; });
  return (
    <section>
      <BarreDocument retour={`#/eleves/${classeId}`} libelle={`Élèves de la ${classe}`} />
      <div className="filtres pas-imprimer"><label><span>Semaine du</span><input type="date" value={semaine} onChange={(e) => setSemaine(lundiDe(e.target.value).toISOString().slice(0, 10))} /></label></div>
      <article className="document">
        <EnteteOfficiel e={ecole} titre={`Fiche d'appel de la ${classe}`}
          sousTitre={`Semaine du ${jours[0].toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })} au ${jours[4].toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}, année ${infos.annee}`} />
        <table className="doc-tableau doc-appel">
          <thead>
            <tr><th rowSpan={2}>N°</th><th rowSpan={2}>Nom et prénoms</th>{jours.map((j) => <th key={j} colSpan={2}>{j.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' })}</th>)}</tr>
            <tr>{jours.map((j) => [<th key={j + 'm'} className="demi">M</th>, <th key={j + 's'} className="demi">S</th>])}</tr>
          </thead>
          <tbody>{eleves.map((x, i) => (
            <tr key={x.matricule}><td className="c">{i + 1}</td><td><strong>{x.nom}</strong> {x.prenoms}</td>{jours.map((j) => [<td key={j + 'm'} />, <td key={j + 's'} />])}</tr>))}
          </tbody>
        </table>
        <p className="doc-pied">A : absent, R : retard (préciser les minutes). M : matin, S : après-midi. À saisir ensuite dans le logiciel, onglet Absences.</p>
      </article>
    </section>
  );
}

// ------------------------------------------------------------------ Certificat de scolarité
export function Certificat({ eleveId }) {
  const d = useDonnees(() => api.get('eleves/certificat', { id: eleveId }), [eleveId]);
  if (d.charge) return <Chargement />;
  if (d.erreur) return <><a href={`#/eleve/${eleveId}`} className="retour">Fiche de l'élève</a><Alerte>{d.erreur}</Alerte></>;
  const { eleve: el, inscription: i, ecole, numero } = d.donnees;
  const f = el.sexe === 'F';
  return (
    <section>
      <BarreDocument retour={`#/eleve/${eleveId}`} libelle="Fiche de l'élève" />
      <article className="document certificat">
        <EnteteOfficiel e={ecole} />
        <h1 className="certificat-titre">Certificat de scolarité</h1>
        <p className="certificat-numero">N° {String(numero).padStart(4, '0')}/{i.annee}</p>
        <div className="certificat-corps">
          <p>{f ? 'Je soussignée' : 'Je soussigné(e)'}, <strong>{ecole.nom_directrice || '……………………'}</strong>, {(ecole.titre_signataire || 'La Directrice').replace(/^(La|Le)\s/, (m) => m.toLowerCase())} de l'établissement <strong>{ecole.nom_officiel}</strong>, certifie que :</p>
          <p className="certificat-eleve">{f ? 'La jeune' : 'Le jeune'} <strong>{el.nom} {el.prenoms}</strong></p>
          <p>{f ? 'née' : 'né'} le <strong>{el.date_naissance ? dateLongue(el.date_naissance) : '……………………'}</strong> à <strong>{el.lieu_naissance || '……………………'}</strong>,
            matricule <strong>{el.matricule}</strong>{el.educmaster ? <>, numéro Educmaster <strong>{el.educmaster}</strong></> : null},</p>
          <p>est {f ? 'régulièrement inscrite' : 'régulièrement inscrit'} dans notre établissement en classe de <strong>{i.classe}</strong> pour l'année scolaire <strong>{i.annee}</strong>.</p>
          <p>En foi de quoi, le présent certificat lui est délivré pour servir et valoir ce que de droit.</p>
        </div>
        <div className="doc-signature certificat-signature">
          <p>Fait à {ecole.ville}, le {dateLongue()}</p>
          <p>{ecole.titre_signataire}</p>
          <div className="recu-cachet">Signature et cachet</div>
          <p className="signature-nom">{ecole.nom_directrice}</p>
        </div>
      </article>
    </section>
  );
}
