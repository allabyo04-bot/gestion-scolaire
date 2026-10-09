import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useMessage, Chargement, Alerte, Champ } from '../composants/commun.jsx';

const v = (x) => (x === null || x === undefined ? '' : String(Number(x)).replace('.', ','));

export default function ParamBulletin({ ecoleId }) {
  const message = useMessage();
  const d = useDonnees(() => api.get('bul/parametres', { ecole_id: ecoleId }), [ecoleId]);
  const [f, setF] = useState(null);
  if (d.charge && !d.donnees) return <Chargement />;
  if (d.erreur) return <Alerte>{d.erreur}</Alerte>;
  const x = f ?? { ...d.donnees, appreciations: d.donnees.appreciations.map(([s, l]) => [v(s), l]),
                   seuil_felicitations: v(d.donnees.seuil_felicitations), seuil_encouragements: v(d.donnees.seuil_encouragements), seuil_tableau: v(d.donnees.seuil_tableau) };
  const maj = (k, val) => setF({ ...x, [k]: val });
  const majLigne = (i, j, val) => maj('appreciations', x.appreciations.map((l, k) => (k === i ? (j === 0 ? [val, l[1]] : [l[0], val]) : l)));
  const enregistrer = async () => {
    try { await api.post('bul/parametres_enregistrer', { ...x, ecole_id: ecoleId, afficher_absences: Number(x.afficher_absences) ? 1 : 0 }); message('Réglages du bulletin enregistrés.'); setF(null); d.recharger(); }
    catch (e) { message(e.message, 'erreur'); }
  };
  return (
    <div className="formulaire-large">
      <h2 className="titre-section">Réglages du bulletin</h2>
      {Number(d.donnees.a_confirmer) ? <Alerte type="info">Valeurs proposées par défaut, à vérifier avec l'école puis à enregistrer.</Alerte> : null}
      <h3 className="titre-sous-section">Appréciations selon la moyenne</h3>
      <p className="discret">Pour chaque ligne : à partir de quelle moyenne l'appréciation s'applique.</p>
      <div className="bareme">
        {x.appreciations.map((l, i) => (
          <div key={i} className="ligne-formulaire">
            <span>À partir de</span>
            <input inputMode="decimal" aria-label="Seuil" value={l[0]} onChange={(e) => majLigne(i, 0, e.target.value)} style={{ maxWidth: '5rem' }} />
            <input aria-label="Appréciation" value={l[1]} onChange={(e) => majLigne(i, 1, e.target.value)} />
            <button className="bouton-lien" onClick={() => maj('appreciations', x.appreciations.filter((_, k) => k !== i))}>Retirer</button>
          </div>
        ))}
        <button className="bouton bouton-discret" onClick={() => maj('appreciations', [...x.appreciations, ['', '']])}>Ajouter une ligne</button>
      </div>
      <h3 className="titre-sous-section">Mentions du conseil (attribuées automatiquement)</h3>
      <p className="discret">La plus haute mention atteinte est cochée. Laissez vide pour ne jamais l'attribuer automatiquement. L'avertissement et le blâme restent à décider au cas par cas.</p>
      <div className="grille-champs">
        <Champ libelle="Félicitations à partir de" id="pb-f"><input id="pb-f" inputMode="decimal" value={x.seuil_felicitations} onChange={(e) => maj('seuil_felicitations', e.target.value)} /></Champ>
        <Champ libelle="Encouragements à partir de" id="pb-e"><input id="pb-e" inputMode="decimal" value={x.seuil_encouragements} onChange={(e) => maj('seuil_encouragements', e.target.value)} /></Champ>
        <Champ libelle="Tableau d'honneur à partir de" id="pb-t"><input id="pb-t" inputMode="decimal" value={x.seuil_tableau} onChange={(e) => maj('seuil_tableau', e.target.value)} /></Champ>
      </div>
      <h3 className="titre-sous-section">Signature et pied de page</h3>
      <div className="grille-champs">
        <Champ libelle="Titre du signataire" id="pb-ts" aide="Exemple : Le Proviseur, La Directrice"><input id="pb-ts" value={x.titre_signataire} onChange={(e) => maj('titre_signataire', e.target.value)} /></Champ>
        <Champ libelle="Nom du signataire" id="pb-ns"><input id="pb-ns" value={x.nom_signataire ?? ''} onChange={(e) => maj('nom_signataire', e.target.value)} /></Champ>
      </div>
      <Champ libelle="Mention en bas du bulletin" id="pb-nb"><input id="pb-nb" value={x.note_bas ?? ''} onChange={(e) => maj('note_bas', e.target.value)} /></Champ>
      <label className="case"><input type="checkbox" checked={!!Number(x.afficher_absences)} onChange={(e) => maj('afficher_absences', e.target.checked ? 1 : 0)} /> Afficher les heures d'absence et les retards sur le bulletin</label>
      <button className="bouton bouton-principal" onClick={enregistrer} disabled={!f && !Number(d.donnees.a_confirmer)}>Enregistrer les réglages</button>
    </div>
  );
}
