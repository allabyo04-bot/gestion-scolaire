import { useEffect } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useGroupe } from './commun.jsx';

// Pour l'administrateur général : choisir l'école sur laquelle travailler
export default function ChoixEcole({ valeur, surChangement, toutes = false }) {
  const { utilisateur } = useSession();
  const { groupeId } = useGroupe();
  const ecoles = useDonnees(() => api.get('ref/ecoles', { groupe_id: groupeId }), [groupeId]);
  useEffect(() => {
    if (!ecoles.donnees) return;
    const dedans = ecoles.donnees.some((e) => e.id === valeur);
    if (!toutes && (!valeur || !dedans) && ecoles.donnees.length) surChangement(ecoles.donnees[0].id);
    if (toutes && valeur && !dedans) surChangement(null);
  }, [ecoles.donnees, valeur, toutes, surChangement]);
  if (utilisateur.role !== 'SUPER_ADMIN' || !ecoles.donnees) return null;
  return (
    <label className="choix-ecole">
      <span>École</span>
      <select value={valeur ?? ''} onChange={(e) => surChangement(e.target.value ? Number(e.target.value) : null)}>
        {toutes && <option value="">Toutes les écoles du groupe</option>}
        {ecoles.donnees.map((e) => <option key={e.id} value={e.id}>{e.nom_officiel} ({e.ville})</option>)}
      </select>
    </label>
  );
}
