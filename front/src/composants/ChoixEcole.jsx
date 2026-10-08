import { useEffect } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession } from './commun.jsx';

// Pour l'administrateur général : choisir l'école sur laquelle travailler
export default function ChoixEcole({ valeur, surChangement, toutes = false }) {
  const { utilisateur } = useSession();
  const ecoles = useDonnees(() => api.get('ref/ecoles'), []);
  useEffect(() => {
    if (!toutes && !valeur && ecoles.donnees?.length) surChangement(ecoles.donnees[0].id);
  }, [ecoles.donnees, valeur, toutes, surChangement]);
  if (utilisateur.role !== 'SUPER_ADMIN' || !ecoles.donnees) return null;
  return (
    <label className="choix-ecole">
      <span>École</span>
      <select value={valeur ?? ''} onChange={(e) => surChangement(e.target.value ? Number(e.target.value) : null)}>
        {toutes && <option value="">Toutes les écoles</option>}
        {ecoles.donnees.map((e) => <option key={e.id} value={e.id}>{e.nom_officiel} ({e.ville})</option>)}
      </select>
    </label>
  );
}
