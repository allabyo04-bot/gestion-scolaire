import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, Chargement, Alerte, gereNotes } from '../composants/commun.jsx';
import { f2 } from './PrimaireSaisie.jsx';

// Bilans du primaire (trimestre ou année) et bulletins A4.
const CODES = { ABSENT: 'A', ABSENT_JUSTIFIE: 'AJ', ABSENT_NON_JUSTIFIE: 'AN', DISPENSE: 'D' };
const nomTrim = (t) => `${Number(t) === 1 ? '1er' : `${t}e`} trimestre`;
const numerosDe = (p, t) => { const debut = p.repartition.slice(0, t - 1).reduce((a, b) => a + b, 0); return Array.from({ length: p.repartition[t - 1] ?? 0 }, (_, i) => debut + i + 1); };
const texteSeuil = (b) => (b ? String(b.seuil) : '—');
const calculArrondi = (b) => (b && b.seuil_brut % 1 !== 0 ? `moyenne ${String(b.seuil_brut).replace('.', ',')} arrondie à ${b.seuil}` : '');
const Etiquette = ({ ok, oui = 'Atteint', non = 'Non atteint' }) => <span className={`etiquette ${ok ? 'etiquette-ok' : 'etiquette-alerte'}`}>{ok ? oui : non}</span>;

export default function PrimaireBilan({ classeId, vue }) {
  const { utilisateur } = useSession();
  const d = useDonnees(() => api.get('prim/bilan', { classe_id: classeId }), [classeId]);
  if (d.charge) return <Chargement texte="Calcul des bilans…" />;
  if (d.erreur) return <><a href="#/classes" className="retour">Classes</a><Alerte>{d.erreur}</Alerte></>;
  const b = d.donnees, p = b.parametres;
  const nbTrim = p.repartition.length;
  const annee = vue === 'annee';
  const t = annee ? null : Math.min(Math.max(Number(vue) || 1, 1), nbTrim);
  const valides = new Set(b.evaluations_validees);
  const peutSaisir = gereNotes(utilisateur) || utilisateur.role === 'PROFESSEUR';

  const resultats = b.eleves.map((e) => (annee ? e.annee : e.trimestres[t]));
  const avec = resultats.filter(Boolean);
  const atteints = avec.filter((r) => r.reussi).length;
  const moyClasse = avec.length ? avec.reduce((s, r) => s + r.moyenne, 0) / avec.length : null;

  return (
    <section>
      <a href="#/classes" className="retour">Classes</a>
      <div className="entete-page entete-avec-action">
        <div>
          <h1>{b.classe.nom} — {annee ? "Bilan de l'année" : `Bilan du ${nomTrim(t)}`}</h1>
          <p>{annee ? `Moyenne des trimestres · passage en classe supérieure avec au moins ${p.passage_min} évaluations réussies sur ${p.nb_evaluations}`
                    : 'Seuil du trimestre = moyenne des seuils de ses évaluations, arrondie'} · seules les évaluations validées comptent.</p>
        </div>
        <div className="groupe-boutons">
          {peutSaisir && <a className="bouton" href={`#/primaire/${classeId}/${annee ? numerosDe(p, nbTrim)[0] : numerosDe(p, t)[0]}`}>Saisie des notes</a>}
          <a className="bouton bouton-principal" href={`#/primaire-bulletins/${classeId}/${annee ? nbTrim : t}`}>Bulletins {annee ? `du ${nomTrim(nbTrim)}` : ''}</a>
        </div>
      </div>

      <nav className="sous-onglets" aria-label="Période">
        {Array.from({ length: nbTrim }, (_, i) => i + 1).map((n) => (
          <a key={n} href={`#/primaire-bilan/${classeId}/${n}`} className={!annee && n === t ? 'actif' : ''} aria-current={!annee && n === t ? 'page' : undefined}>{nomTrim(n)}</a>
        ))}
        <a href={`#/primaire-bilan/${classeId}/annee`} className={annee ? 'actif' : ''} aria-current={annee ? 'page' : undefined}>Année</a>
      </nav>

      {p.a_confirmer && <Alerte type="info">Règles de calcul par défaut, à confirmer par l'école (seuil des deux tiers, arrondi, passage à {p.passage_min} sur {p.nb_evaluations}).</Alerte>}

      <div className="cartes-bilan">
        <div className="carte-chiffre"><span>Seuil {annee ? 'annuel' : 'du trimestre'} atteint</span><strong className="ok">{atteints} sur {b.eleves.length}</strong></div>
        <div className="carte-chiffre"><span>Moyenne de la classe</span><strong>{moyClasse === null ? '—' : f2(moyClasse)}</strong></div>
        <div className="carte-chiffre"><span>Élèves à suivre</span><strong className="alerte">{avec.length - atteints}</strong></div>
      </div>

      {avec.length === 0 && <div className="vide"><p>Aucune évaluation validée pour cette période.</p></div>}
      {avec.length > 0 && (annee ? <TableAnnee b={b} /> : <TableTrimestre b={b} t={t} valides={valides} />)}
    </section>
  );
}

function TableTrimestre({ b, t, valides }) {
  const nums = numerosDe(b.parametres, t);
  return (
    <div className="tableau-defilant">
      <table className="tableau bilan-primaire">
        <thead>
          <tr>
            <th scope="col" rowSpan={2} className="g">Élève</th>
            {nums.map((n) => <th key={n} scope="colgroup" colSpan={2}>Évaluation {n}{!valides.has(n) && <small> (non validée)</small>}</th>)}
            <th scope="colgroup" colSpan={3} className="calc">{nomTrim(t)}</th>
            <th scope="col" rowSpan={2}>Évaluations réussies</th>
          </tr>
          <tr>
            {nums.map((n) => [<th key={`m${n}`} scope="col">Moyenne</th>, <th key={`s${n}`} scope="col">Seuil</th>])}
            <th scope="col" className="calc">Moyenne</th><th scope="col" className="calc">Seuil</th><th scope="col" className="calc">Résultat</th>
          </tr>
        </thead>
        <tbody>
          {b.eleves.map((e) => {
            const tr = e.trimestres[t];
            return (
              <tr key={e.inscription_id}>
                <th scope="row" className="g">{e.nom} {e.prenoms}</th>
                {nums.map((n) => {
                  const c = e.evaluations[n]?.calcul;
                  return [<td key={`m${n}`}>{c ? f2(c.moyenne) : '—'}</td>,
                          <td key={`s${n}`} className={c ? (c.reussi ? 'texte-ok' : 'texte-alerte') : ''}>{c ? `${c.seuil}/${c.composees}` : '—'}</td>];
                })}
                <td className="calc fort">{tr ? f2(tr.moyenne) : '—'}</td>
                <td className="calc"><strong>{texteSeuil(tr)}</strong> {tr && <small className="discret">{calculArrondi(tr)}</small>}</td>
                <td className="calc">{tr ? <Etiquette ok={tr.reussi} /> : ''}</td>
                <td>{e.reussies} sur {b.evaluations_validees.length}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function TableAnnee({ b }) {
  const p = b.parametres, nbTrim = p.repartition.length;
  const trims = Array.from({ length: nbTrim }, (_, i) => i + 1);
  return (
    <div className="tableau-defilant">
      <table className="tableau bilan-primaire">
        <thead>
          <tr>
            <th scope="col" rowSpan={2} className="g">Élève</th>
            {trims.map((n) => <th key={n} scope="colgroup" colSpan={2}>{nomTrim(n)}</th>)}
            <th scope="colgroup" colSpan={2} className="calc">Année</th>
            <th scope="col" rowSpan={2}>Évaluations réussies</th>
            <th scope="col" rowSpan={2}>Décision</th>
          </tr>
          <tr>
            {trims.map((n) => [<th key={`m${n}`} scope="col">Moyenne</th>, <th key={`s${n}`} scope="col">Seuil</th>])}
            <th scope="col" className="calc">Moyenne</th><th scope="col" className="calc">Seuil</th>
          </tr>
        </thead>
        <tbody>
          {b.eleves.map((e) => (
            <tr key={e.inscription_id}>
              <th scope="row" className="g">{e.nom} {e.prenoms}</th>
              {trims.map((n) => { const x = e.trimestres[n]; return [<td key={`m${n}`}>{x ? f2(x.moyenne) : '—'}</td>, <td key={`s${n}`}>{x ? x.seuil : '—'}</td>]; })}
              <td className="calc fort">{e.annee ? f2(e.annee.moyenne) : '—'}</td>
              <td className="calc fort">{e.annee ? e.annee.seuil : '—'}</td>
              <td>{e.reussies} sur {p.nb_evaluations}</td>
              <td>{e.annee?.decision ? <Etiquette ok={e.annee.decision === 'PASSE'} oui="Passe" non="Redouble" /> : <span className="discret" title={`Décision après les ${p.nb_evaluations} évaluations`}>En attente</span>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ---------------------------------------------------------------------
// Bulletins A4 d'un trimestre (le dernier trimestre porte aussi le bilan annuel)
// ---------------------------------------------------------------------
export function PrimaireBulletins({ classeId, trimestre }) {
  const d = useDonnees(() => api.get('prim/bilan', { classe_id: classeId }), [classeId]);
  const [eleve, setEleve] = useState('');
  if (d.charge) return <Chargement texte="Préparation des bulletins…" />;
  if (d.erreur) return <><a href="#/classes" className="retour">Classes</a><Alerte>{d.erreur}</Alerte></>;
  const b = d.donnees, p = b.parametres;
  const t = Math.min(Math.max(Number(trimestre) || 1, 1), p.repartition.length);
  const nums = numerosDe(p, t);
  const manquantes = nums.filter((n) => !b.evaluations_validees.includes(n));
  const affiches = eleve ? b.eleves.filter((e) => String(e.inscription_id) === eleve) : b.eleves;
  return (
    <section>
      <div className="pas-imprimer">
        <a href={`#/primaire-bilan/${classeId}/${t}`} className="retour">Bilan</a>
        <div className="entete-page entete-avec-action">
          <div><h1>Bulletins {b.classe.nom}</h1><p>{nomTrim(t)}, {b.eleves.length} élève(s).</p></div>
          <div className="groupe-boutons">
            <select aria-label="Élève" value={eleve} onChange={(e) => setEleve(e.target.value)}>
              <option value="">Toute la classe</option>
              {b.eleves.map((e) => <option key={e.inscription_id} value={e.inscription_id}>{e.nom} {e.prenoms}</option>)}
            </select>
            <button className="bouton bouton-principal" onClick={() => window.print()}>Imprimer {eleve ? 'ce bulletin' : `les ${affiches.length} bulletins`}</button>
          </div>
        </div>
        {manquantes.length > 0 && <Alerte type="info">Évaluation(s) {manquantes.join(' et ')} pas encore validée(s) : elles n'apparaissent pas sur les bulletins.</Alerte>}
      </div>
      <div className="pile-bulletins">{affiches.map((e) => <BulletinPrimaire key={e.inscription_id} b={b} e={e} t={t} nums={nums} />)}</div>
    </section>
  );
}

function BulletinPrimaire({ b, e, t, nums }) {
  const ec = b.ecole, img = ec.images ?? {}, p = b.parametres;
  const dernier = t === p.repartition.length;
  const tr = e.trimestres[t];
  const cellule = (n, cm) => {
    const x = e.evaluations[n]?.notes?.[cm];
    if (!x) return { texte: '', fort: false };
    if (x.statut !== 'NOTE') return { texte: CODES[x.statut], fort: false };
    return { texte: String(Number(x.valeur)).replace('.', ','), fort: Number(x.valeur) >= p.note_reussite };
  };
  const totaux = nums.map((n) => Object.values(e.evaluations[n]?.notes ?? {}).reduce((s, x) => s + (x.statut === 'NOTE' ? Number(x.valeur) : 0), 0));
  const naissance = [e.date_naissance && e.date_naissance.split('-').reverse().join('/'), e.lieu_naissance].filter(Boolean).join(' à ');
  return (
    <article className="bulletin bul-primaire">
      <header className="bul-entete">
        <div className="bul-logo">{img.LOGO && <img src={img.LOGO} alt="" />}</div>
        <div className="bul-titres">
          <p className="bul-l1">République du Bénin</p>
          {ec.entete_ligne1 && <p className="bul-l1">{ec.entete_ligne1}</p>}
          {ec.entete_ligne2 && <p className="bul-l2">{ec.entete_ligne2}</p>}
          <p className="bul-ecole">{ec.nom_officiel} ({ec.ville})</p>
        </div>
        <div className="bul-logo">{img.LOGO_FONDATION && <img src={img.LOGO_FONDATION} alt="" />}</div>
      </header>
      <p className="bul-bandeau">BULLETIN D'ÉVALUATION — {nomTrim(t).toUpperCase()} — {b.classe.annee}</p>
      <div className="bp-identite">
        <p><span className="bul-etiq">Nom et prénoms :</span> <strong>{e.nom} {e.prenoms}</strong></p>
        <p><span className="bul-etiq">Classe :</span> <strong>{b.classe.nom}</strong></p>
        <p><span className="bul-etiq">Matricule :</span> {e.matricule}</p>
        <p><span className="bul-etiq">Sexe :</span> {e.sexe}</p>
        <p><span className="bul-etiq">Né(e) le :</span> {naissance || '……………'}</p>
        <p><span className="bul-etiq">Titulaire :</span> {b.classe.titulaire ?? '……………'}</p>
      </div>
      <table className="bul-notes">
        <thead><tr><th className="g">Matières</th>{nums.map((n) => <th key={n}>Évaluation {n}</th>)}</tr></thead>
        <tbody>
          {b.matieres.map((m) => (
            <tr key={m.id}><td className="g bul-mat">{m.libelle}</td>{nums.map((n) => { const c = cellule(n, m.id); return <td key={n} className={c.fort ? 'fort' : ''}>{c.texte}</td>; })}</tr>
          ))}
          <tr className="bul-totaux"><td className="g">Total des notes</td>{nums.map((n, i) => <td key={n}>{e.evaluations[n] ? totaux[i] : ''}</td>)}</tr>
          <tr className="bul-totaux"><td className="g">Moyenne sur 20</td>{nums.map((n) => <td key={n}>{e.evaluations[n]?.calcul ? f2(e.evaluations[n].calcul.moyenne) : ''}</td>)}</tr>
          <tr className="bul-totaux"><td className="g">Seuil (matières à {String(p.note_reussite).replace('.', ',')} ou plus)</td>
            {nums.map((n) => { const c = e.evaluations[n]?.calcul; return <td key={n}>{c ? `${c.seuil}/${c.composees} — ${c.reussi ? 'atteint' : 'non atteint'}` : ''}</td>; })}</tr>
        </tbody>
      </table>
      <p className="bp-note">Notes en gras : matières réussies. Seuil de réussite : {Math.ceil(b.matieres.length * p.seuil_num / p.seuil_den - 1e-9)} matières réussies sur {b.matieres.length}. A : absent, AJ : absent justifié, AN : absent non justifié, D : dispensé.</p>

      <div className="bp-bilan">
        <div><span>Moyenne du trimestre</span><strong>{tr ? `${f2(tr.moyenne)} / 20` : '—'}</strong></div>
        <div><span>Seuil du trimestre</span><strong>{tr ? `${tr.seuil}` : '—'}</strong>{tr && calculArrondi(tr) && <small>{calculArrondi(tr)}</small>}</div>
        <div><span>Résultat</span><strong>{tr ? (tr.reussi ? 'Seuil atteint' : 'Seuil non atteint') : '—'}</strong><small>Évaluations réussies : {e.reussies} sur {p.nb_evaluations}</small></div>
      </div>

      {dernier && (
        <table className="bul-recap bp-annee">
          <thead><tr><th className="g">Bilan de l'année</th>{p.repartition.map((_, i) => <th key={i}>{nomTrim(i + 1)}</th>)}<th>Année</th></tr></thead>
          <tbody>
            <tr><td className="g">Moyenne</td>{p.repartition.map((_, i) => <td key={i}>{e.trimestres[i + 1] ? f2(e.trimestres[i + 1].moyenne) : '—'}</td>)}<td className="fort">{e.annee ? f2(e.annee.moyenne) : '—'}</td></tr>
            <tr><td className="g">Seuil</td>{p.repartition.map((_, i) => <td key={i}>{e.trimestres[i + 1]?.seuil ?? '—'}</td>)}<td className="fort">{e.annee?.seuil ?? '—'}</td></tr>
            <tr><td className="g">Décision</td><td colSpan={p.repartition.length + 1} className="fort">
              {e.annee?.decision === 'PASSE' ? 'Admis(e) en classe supérieure' : e.annee?.decision === 'REDOUBLE' ? 'Redouble la classe' : `En attente (passage avec au moins ${p.passage_min} évaluations réussies sur ${p.nb_evaluations})`}
            </td></tr>
          </tbody>
        </table>
      )}

      <div className="bp-appreciation">
        <p className="bul-titre-case">Appréciation de l'enseignant</p>
        <p className="bul-ligne" /><p className="bul-ligne" />
      </div>
      <div className="bp-signatures">
        <div><p className="bul-titre-sig">Signature des parents</p></div>
        <div className="bul-signature">
          <p>{ec.ville}, le ……………………</p>
          <p className="bul-titre-sig">{ec.titre_signataire || 'Le Directeur'}</p>
          <div className="bul-sig-img">{img.CACHET && <img className="img-cachet" src={img.CACHET} alt="" />}{img.SIGNATURE && <img className="img-signature" src={img.SIGNATURE} alt="" />}</div>
          <p className="bul-nom-sig">{ec.nom_directrice ?? ''}</p>
        </div>
      </div>
    </article>
  );
}
