import { useMemo, useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, Chargement, Alerte, estDirection } from '../composants/commun.jsx';

export default function Accueil() {
  const { utilisateur } = useSession();
  const direction = estDirection(utilisateur);
  const prof = utilisateur.role === 'PROFESSEUR';
  const { donnees, erreur, charge, recharger } = useDonnees(() => api.get('notes/mes_affectations'), []);
  const [filtre, setFiltre] = useState('');

  const groupes = useMemo(() => {
    const g = new Map();
    const f = filtre.trim().toLowerCase();
    for (const a of donnees ?? []) {
      if (f && !`${a.classe} ${a.matiere} ${a.professeur ?? ''}`.toLowerCase().includes(f)) continue;
      const cle = `${a.ecole_id}-${a.classe_id}`;
      if (!g.has(cle)) g.set(cle, { classe: a.classe, ecole: a.ecole, matieres: [] });
      g.get(cle).matieres.push(a);
    }
    return [...g.values()];
  }, [donnees, filtre]);

  return (
    <section>
      <div className="entete-page">
        <h1>{prof ? 'Mes classes' : 'Saisie des notes'}</h1>
        <p>{prof ? 'Choisissez une matière pour saisir ou consulter les notes.' : 'Toutes les matières de l\'année en cours.'}</p>
      </div>
      {!prof && (donnees?.length ?? 0) > 8 && (
        <input className="recherche" type="search" placeholder="Rechercher une classe, une matière, un professeur"
               aria-label="Rechercher" value={filtre} onChange={(e) => setFiltre(e.target.value)} />
      )}
      {charge && <Chargement />}
      {erreur && <Alerte action={<button className="bouton" onClick={recharger}>Réessayer</button>}>{erreur}</Alerte>}
      {!charge && !erreur && groupes.length === 0 && (
        <div className="vide">
          {prof ? <p>Aucune matière ne vous est encore affectée. La direction doit vous attribuer vos classes.</p>
                : <p>Aucune matière pour l'année en cours. Créez d'abord les classes et leurs matières.</p>}
        </div>
      )}
      <div className="liste-classes">
        {groupes.map((g) => (
          <article key={g.classe + g.ecole} className="bloc-classe">
            <h2>{g.classe}{utilisateur.role === 'SUPER_ADMIN' && <span className="discret"> — {g.ecole}</span>}</h2>
            <ul>
              {g.matieres.map((m) => (
                <li key={m.classe_matiere_id}>
                  <a href={`#/saisie/${m.classe_matiere_id}`} className="ligne-matiere">
                    <span className="matiere-nom">{m.matiere}</span>
                    <span className="matiere-coef">Coef. {Number(m.coefficient).toString().replace('.', ',')}</span>
                    {direction && <span className="matiere-prof">{m.professeur ?? <em className="manque">Aucun professeur affecté</em>}</span>}
                  </a>
                </li>
              ))}
            </ul>
          </article>
        ))}
      </div>
    </section>
  );
}
