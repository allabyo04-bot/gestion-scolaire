import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useMessage, Chargement, Alerte, Champ } from '../composants/commun.jsx';

// Règles de calcul du primaire de l'école (seuil, répartition des évaluations, passage)
export default function ParamPrimaire({ ecoleId }) {
  const message = useMessage();
  const d = useDonnees(() => api.get('prim/parametres', { ecole_id: ecoleId }), [ecoleId]);
  const [f, setF] = useState(null);
  if (d.charge && !d.donnees) return <Chargement />;
  if (d.erreur) return <Alerte>{d.erreur}</Alerte>;
  const p = d.donnees;
  const x = f ?? { note_reussite: String(p.note_reussite).replace('.', ','), seuil_num: p.seuil_num, seuil_den: p.seuil_den, repartition: p.repartition.join(','), passage_min: p.passage_min };
  const maj = (k) => (e) => setF({ ...x, [k]: e.target.value });
  const enregistrer = async () => {
    try { await api.post('prim/parametres_enregistrer', { ...x, ecole_id: ecoleId }); message('Règles du primaire enregistrées.'); setF(null); d.recharger(); }
    catch (e) { message(e.message, 'erreur'); }
  };
  const nb = String(x.repartition).split(',').map(Number).filter(Boolean).reduce((a, b) => a + b, 0);
  return (
    <div className="formulaire-large">
      <h2 className="titre-section">Règles du primaire</h2>
      {p.a_confirmer && <Alerte type="info">Valeurs proposées par défaut, à vérifier avec l'école puis à enregistrer.</Alerte>}
      <p className="discret">Pas de coefficient au primaire : la moyenne d'une évaluation est la somme des notes divisée par le nombre de matières composées.</p>
      <Champ libelle="Note à partir de laquelle une matière est réussie" id="pp-note"><input id="pp-note" inputMode="decimal" value={x.note_reussite} onChange={maj('note_reussite')} /></Champ>
      <div className="ligne-formulaire">
        <span>Seuil de réussite : au moins</span>
        <input aria-label="Numérateur du seuil" className="court" inputMode="numeric" value={x.seuil_num} onChange={maj('seuil_num')} />
        <span>sur</span>
        <input aria-label="Dénominateur du seuil" className="court" inputMode="numeric" value={x.seuil_den} onChange={maj('seuil_den')} />
        <span>des matières composées (2 sur 3 : 6 matières sur 9)</span>
      </div>
      <Champ libelle="Nombre d'évaluations par trimestre" id="pp-rep" aide="Séparés par des virgules, dans l'ordre des trimestres. Exemple : 2,2,1 (5 évaluations dans l'année)."><input id="pp-rep" value={x.repartition} onChange={maj('repartition')} /></Champ>
      <Champ libelle="Évaluations réussies pour passer en classe supérieure" id="pp-pass" aide={`Sur ${nb} évaluations dans l'année.`}><input id="pp-pass" inputMode="numeric" value={x.passage_min} onChange={maj('passage_min')} /></Champ>
      <button className="bouton bouton-principal" onClick={enregistrer}>Enregistrer</button>
    </div>
  );
}
