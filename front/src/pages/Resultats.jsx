import { api } from '../api.js';
import { useDonnees, Chargement, Alerte, formatNote, rangTexte } from '../composants/commun.jsx';

export default function Resultats({ classeId, periodeId }) {
  const r = useDonnees(() => api.get('resultats/classe', { classe_id: classeId, periode_id: periodeId }), [classeId, periodeId]);
  if (r.charge) return <Chargement />;
  if (r.erreur) return <><a href="#/classes" className="retour">Classes</a><Alerte>{r.erreur}</Alerte></>;
  const { classe, periode, matieres, eleves, statistiques: st } = r.donnees;
  const tries = [...eleves].sort((a, b) => (a.rang ?? 999) - (b.rang ?? 999) || a.nom.localeCompare(b.nom));

  return (
    <section>
      <a href="#/classes" className="retour">Classes</a>
      <div className="entete-page">
        <h1>{classe} <span className="discret">{periode}</span></h1>
        <p>Moyennes calculées sur les notes validées uniquement.</p>
      </div>
      {st && (
        <dl className="statistiques">
          <div><dt>Moyenne de la classe</dt><dd>{formatNote(st.moyenne_classe)}</dd></div>
          <div><dt>Plus forte</dt><dd>{formatNote(st.plus_forte)}</dd></div>
          <div><dt>Plus faible</dt><dd>{formatNote(st.plus_faible)}</dd></div>
          <div><dt>Élèves classés</dt><dd>{st.classes} / {st.effectif}</dd></div>
        </dl>
      )}
      {!st && <div className="vide"><p>Aucune note validée pour cette période. Les moyennes apparaîtront dès la première validation.</p></div>}
      {st && (
        <div className="tableau-defilant">
          <table className="tableau tableau-resultats">
            <thead>
              <tr>
                <th scope="col" className="col-fixe">Élève</th>
                {matieres.map((m) => <th key={m.id} scope="col" className="nombre" title={m.libelle}>{m.libelle}<small>coef. {Number(m.coefficient)}</small></th>)}
                <th scope="col" className="nombre col-moyenne">Moyenne</th>
                <th scope="col" className="nombre">Rang</th>
              </tr>
            </thead>
            <tbody>
              {tries.map((e) => (
                <tr key={e.inscription_id} className={e.moyenne_generale !== null && e.moyenne_generale < 10 ? 'sous-moyenne' : ''}>
                  <th scope="row" className="col-fixe">{e.nom} {e.prenoms}</th>
                  {matieres.map((m) => {
                    const x = e.matieres[m.id];
                    return <td key={m.id} className="nombre">{x ? <>{formatNote(x.moyenne)}<small>{rangTexte(x.rang, x.ex_aequo, e.sexe)}</small></> : '—'}</td>;
                  })}
                  <td className="nombre col-moyenne">{formatNote(e.moyenne_generale) || '—'}</td>
                  <td className="nombre">{rangTexte(e.rang, e.ex_aequo, e.sexe)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
