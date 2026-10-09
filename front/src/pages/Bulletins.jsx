import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, Chargement, Alerte } from '../composants/commun.jsx';

const n2 = (v) => (v === null || v === undefined ? '' : Number(v).toFixed(2).replace('.', ','));
const ordinal = (r, ex, sexe) => (!r ? '' : `${r}${r === 1 ? (sexe === 'F' ? 're' : 'er') : 'e'}${ex ? ' ex' : ''}`);
const PERIODES = ['', 'PREMIER', 'DEUXIÈME', 'TROISIÈME'];
const titrePeriode = (p) => /semestre/i.test(p.libelle) ? `BULLETIN DE NOTES DU ${PERIODES[p.numero]} SEMESTRE` : `BULLETIN DE NOTES DU ${PERIODES[p.numero]} TRIMESTRE`;
const dateFr = (d) => (d ? d.split('-').reverse().join('/') : '');
const espaces = (t) => String(t ?? '').replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1 ');
const MENTIONS = [['FELICITATIONS', 'Félicitations'], ['TABLEAU_HONNEUR', "Tableau d'honneur"], ['ENCOURAGEMENTS', 'Encouragements'], ['AVERTISSEMENT', 'Avertissement'], ['BLAME', 'Blâme']];

export default function Bulletins({ classeId, periodeId }) {
  const d = useDonnees(() => api.get('bul/classe', { classe_id: classeId, periode_id: periodeId }), [classeId, periodeId]);
  const [eleve, setEleve] = useState('');
  if (d.charge) return <Chargement texte="Calcul des bulletins…" />;
  if (d.erreur) return <><a href="#/classes" className="retour">Classes</a><Alerte>{d.erreur}</Alerte></>;
  const b = d.donnees;
  const liste = [...b.bulletins].sort((x, y) => x.eleve.nom.localeCompare(y.eleve.nom) || x.eleve.prenoms.localeCompare(y.eleve.prenoms));
  const affiches = eleve ? liste.filter((x) => String(x.eleve.id) === eleve) : liste;
  return (
    <section>
      <div className="pas-imprimer">
        <a href="#/classes" className="retour">Classes</a>
        <div className="entete-page entete-avec-action">
          <div><h1>Bulletins {b.classe}</h1><p>{b.periode.libelle}, {liste.length} élève(s).</p></div>
          <div className="groupe-boutons">
            <select aria-label="Élève" value={eleve} onChange={(e) => setEleve(e.target.value)}>
              <option value="">Toute la classe</option>
              {liste.map((x) => <option key={x.eleve.id} value={x.eleve.id}>{x.eleve.nom} {x.eleve.prenoms}</option>)}
            </select>
            <button className="bouton bouton-principal" onClick={() => window.print()}>Imprimer {eleve ? 'ce bulletin' : `les ${liste.length} bulletins`}</button>
          </div>
        </div>
        {b.controles.length > 0 && (
          <Alerte type="info"><strong>Avant d'imprimer, vérifiez :</strong><ul className="liste-controles">{b.controles.map((c) => <li key={c}>{c}</li>)}</ul></Alerte>
        )}
      </div>
      <div className="pile-bulletins">{affiches.map((x) => <Bulletin key={x.eleve.id} b={b} x={x} />)}</div>
    </section>
  );
}

function Bulletin({ b, x }) {
  const e = b.ecole, p = b.parametres, el = x.eleve, img = e.images ?? {};
  const nbDev = b.colonnes.devoirs, dtl = b.colonnes.dtl;
  const coord = [e.boite_postale, e.telephone && `Tél : ${e.telephone}`, e.email && `E-mail : ${e.email}`, e.site_web && `Site : ${e.site_web}`].filter(Boolean).join('   ');
  return (
    <article className="bulletin">
      <header className="bul-entete">
        <div className="bul-logo">{img.LOGO && <img src={img.LOGO} alt="" />}</div>
        <div className="bul-titres">
          {e.entete_ligne1 && <p className="bul-l1">{e.entete_ligne1}</p>}
          {e.entete_ligne2 && <p className="bul-l2">{e.entete_ligne2}</p>}
          {e.entete_ligne3 && <p className="bul-l3">{e.entete_ligne3}</p>}
          <p className="bul-ecole">{e.nom_officiel} ({e.ville})</p>
        </div>
        <div className="bul-logo">{img.LOGO_FONDATION && <img src={img.LOGO_FONDATION} alt="" />}</div>
        {coord && <p className="bul-coord">{coord}</p>}
      </header>

      <div className="bul-identite">
        <div className="bul-cadre">
          <p><span className="bul-matricule">{el.matricule}</span> <strong>{el.nom} {el.prenoms}</strong></p>
          <p><span className="bul-etiq">Né{el.sexe === 'F' ? 'e' : ''} le :</span> {dateFr(el.date_naissance) || '…………'}</p>
          <p><span className="bul-etiq">Lieu :</span> {el.lieu_naissance || '…………'} <span className="bul-etiq bul-sexe">Sexe :</span> {el.sexe}</p>
          <p><span className="bul-etiq">Numéro Educmaster :</span> <strong className="chiffres">{espaces(el.educmaster) || '…………'}</strong></p>
        </div>
        <div className="bul-cadre">
          <p><span className="bul-etiq">Classe :</span> <strong>{b.classe}</strong></p>
          <p><span className="bul-etiq">Effectif :</span> {b.effectif}</p>
          <p><span className="bul-etiq">Statut :</span> {Number(el.redoublant) ? (el.sexe === 'F' ? 'redoublante' : 'redoublant') : 'passant' + (el.sexe === 'F' ? 'e' : '')}</p>
          <p><span className="bul-etiq">Année scolaire :</span> <strong>{b.annee}</strong></p>
        </div>
        <div className="bul-photo">Photo</div>
      </div>

      <h2 className="bul-bandeau">{titrePeriode(b.periode)}</h2>

      <table className="bul-notes">
        <thead><tr>
          <th className="g">Disciplines</th><th>Coef</th><th>Moy. Inter</th>{dtl && <th>DTL</th>}
          {Array.from({ length: nbDev }, (_, k) => <th key={k}>{k === 0 ? '1er' : `${k + 1}e`} Devoir</th>)}
          <th>Moy/20</th><th>Moy. Coef</th><th>Rang</th><th>Appréciations</th><th className="g">Nom du Prof.</th>
        </tr></thead>
        <tbody>
          {x.lignes.map((l) => (
            <tr key={l.matiere}>
              <td className="g bul-mat">{l.matiere}</td><td>{String(l.coefficient).replace('.', ',')}</td>
              <td>{l.conduite ? '' : n2(l.moy_interros)}</td>{dtl && <td>{l.conduite ? '' : (l.dtl !== null ? n2(l.dtl) : '-')}</td>}
              {Array.from({ length: nbDev }, (_, k) => <td key={k}>{l.conduite ? '' : n2(l.devoirs[k + 1])}</td>)}
              <td className="fort">{n2(l.moyenne)}</td><td>{n2(l.points)}</td><td>{ordinal(l.rang, l.ex_aequo, el.sexe)}</td>
              <td className="bul-app">{l.appreciation}</td><td className="g bul-prof">{l.professeur}</td>
            </tr>
          ))}
          <tr className="bul-totaux"><td className="g">TOTAUX</td><td>{String(x.total_coefficients ?? '').replace('.', ',')}</td>
            <td colSpan={3 + (dtl ? 1 : 0) + nbDev - 2 + 1}></td><td className="fort">{n2(x.total_points)}</td><td colSpan={3}></td></tr>
        </tbody>
      </table>

      <div className="bul-resultats">
        <table className="bul-moyenne">
          <tbody>
            <tr><th>Moyenne :</th><td className="bul-sombre">{n2(x.moyenne)}</td><th>Rang :</th><td className="bul-sombre">{ordinal(x.rang, x.ex_aequo, el.sexe)}</td></tr>
            {x.bilans.map((g) => <tr key={g.groupe}><th colSpan={2} className="g">Bilan {g.groupe.toLowerCase()} (moy. et appréciation)</th><td>{n2(g.moyenne)}</td><td>{g.appreciation}</td></tr>)}
          </tbody>
        </table>
        <table className="bul-recap">
          <thead><tr><th>{/semestre/i.test(b.periode.libelle) ? 'Semestre' : 'Trimestre'}</th><th>Moy.</th><th>Rang</th></tr></thead>
          <tbody>{x.recap.map((r) => <tr key={r.periode}><td className="g">{r.periode}</td><td className="fort">{n2(r.moyenne)}</td><td>{ordinal(r.rang, r.ex_aequo, el.sexe)}</td></tr>)}</tbody>
        </table>
        <table className="bul-classe">
          <thead><tr><th colSpan={2}>Bilan de classe</th></tr></thead>
          <tbody>
            <tr><td className="g">Moy. forte</td><td>{n2(b.statistiques?.plus_forte)}</td></tr>
            <tr><td className="g">Moy. faible</td><td>{n2(b.statistiques?.plus_faible)}</td></tr>
            <tr><td className="g">Moy. de classe</td><td>{n2(b.statistiques?.moyenne_classe)}</td></tr>
          </tbody>
        </table>
      </div>
      {x.absences && <p className="bul-absences">Absences du {b.periode.libelle.toLowerCase()} : {String(x.absences.justifiees).replace('.', ',')} h justifiées, {String(x.absences.non_justifiees).replace('.', ',')} h non justifiées, {x.absences.retards} retard(s).</p>}

      <div className="bul-bas">
        <div className="bul-case"><p className="bul-titre-case">Appréciations</p><p className="bul-travail"><span>Travail</span> <strong>{x.appreciation}</strong></p><p className="bul-ligne" /><p className="bul-ligne" /></div>
        <div className="bul-case"><p className="bul-titre-case">Mentions du Conseil des Professeurs</p>
          <ul className="bul-mentions">{MENTIONS.map(([k, v]) => <li key={k}><span>{v}</span><span className="bul-coche">{x.mention === k ? '✓' : ''}</span></li>)}</ul>
        </div>
        <div className="bul-case bul-signature"><p className="bul-titre-case">Signature et cachet du chef d'établissement</p>
          <p className="bul-titre-sig">{p.titre_signataire}</p>
          <div className="bul-sig-img">{img.CACHET && <img className="img-cachet" src={img.CACHET} alt="" />}{img.SIGNATURE && <img className="img-signature" src={img.SIGNATURE} alt="" />}</div>
          <p className="bul-nom-sig">{p.nom_signataire}</p>
        </div>
      </div>
      <footer className="bul-pied"><span>{b.groupe?.sigle ? `${b.groupe.sigle} / ` : ''}Édité le : {dateFr(b.edite_le)}</span><span>{p.note_bas}</span></footer>
    </article>
  );
}
