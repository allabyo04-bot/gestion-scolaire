import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, estDirection } from '../composants/commun.jsx';
import ChoixEcole from '../composants/ChoixEcole.jsx';

export default function Classes() {
  const { utilisateur } = useSession();
  const direction = estDirection(utilisateur);
  const [ecoleId, setEcoleId] = useState(utilisateur.ecole?.id ?? null);
  const pret = !!ecoleId;
  const classes = useDonnees(() => pret ? api.get('ref/classes', { ecole_id: ecoleId }) : Promise.resolve(null), [ecoleId]);
  const periodes = useDonnees(() => pret ? api.get('ref/periodes', { ecole_id: ecoleId }) : Promise.resolve(null), [ecoleId]);
  const [preparer, setPreparer] = useState(null);

  const cycles = {};
  for (const c of classes.donnees ?? []) (cycles[c.cycle] ??= []).push(c);
  const nomsCycles = { MATERNELLE: 'Maternelle', PRIMAIRE: 'Primaire', COLLEGE: 'Collège', LYCEE: 'Lycée' };

  return (
    <section>
      <div className="entete-page">
        <h1>{utilisateur.role === 'PROFESSEUR' ? 'Mes classes principales' : 'Classes'}</h1>
        <p>Consultez les moyennes et les rangs par période.</p>
      </div>
      <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} />
      {(classes.charge || periodes.charge) && <Chargement />}
      {(classes.erreur || periodes.erreur) && <Alerte>{classes.erreur || periodes.erreur}</Alerte>}
      {classes.donnees?.length === 0 && (
        <div className="vide"><p>{utilisateur.role === 'PROFESSEUR' ? "Vous n'êtes professeur principal d'aucune classe." : 'Aucune classe pour l\'année en cours.'}</p></div>
      )}
      {Object.entries(cycles).map(([cycle, liste]) => (
        <div key={cycle} className="groupe-cycle">
          <h2>{nomsCycles[cycle]}</h2>
          <div className="tableau-defilant">
            <table className="tableau">
              <thead><tr><th scope="col">Classe</th><th scope="col" className="nombre">Élèves</th><th scope="col">Résultats et bulletins</th>{direction && <th scope="col"><span className="visuellement-cache">Actions</span></th>}</tr></thead>
              <tbody>
                {liste.map((c) => (
                  <tr key={c.id}>
                    <th scope="row">{c.nom}</th>
                    <td className="nombre">{c.effectif}</td>
                    <td className="liens-periodes">
                      {periodes.donnees?.map((p) => <span key={p.id} className="lien-periode">{p.libelle} : <a href={`#/resultats/${c.id}/${p.id}`}>moyennes</a>, <a href={`#/bulletins/${c.id}/${p.id}`}>bulletins</a></span>)}
                    </td>
                    {direction && <td><button className="bouton bouton-discret" onClick={() => setPreparer(c)}>Préparer les évaluations</button></td>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ))}
      {preparer && <FenetrePreparer classe={preparer} periodes={periodes.donnees ?? []} surFermer={() => setPreparer(null)} />}
    </section>
  );
}

function FenetrePreparer({ classe, periodes, surFermer }) {
  const message = useMessage();
  const ouvertes = periodes.filter((p) => p.statut === 'OUVERTE');
  const [periodeId, setPeriodeId] = useState(ouvertes[0]?.id ?? '');
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    try {
      const r = await api.post('notes/generer_evaluations', { classe_id: classe.id, periode_id: Number(periodeId) });
      message(r.evaluations_creees ? `${r.evaluations_creees} évaluation(s) créée(s) en ${classe.nom}.` : 'Toutes les évaluations existaient déjà.');
      surFermer();
    } catch (e) { setErreur(e.message); }
  };
  return (
    <Fenetre titre={`Préparer les évaluations : ${classe.nom}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer} disabled={!periodeId}>Préparer</button></>}>
      <p>Crée les interros et devoirs de toutes les matières de la classe, selon le paramétrage de l'école. Les évaluations déjà existantes ne sont pas modifiées.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      {ouvertes.length === 0 ? <Alerte type="info">Aucune période ouverte.</Alerte> : (
        <Champ libelle="Période" id="p-periode">
          <select id="p-periode" value={periodeId} onChange={(e) => setPeriodeId(e.target.value)}>
            {ouvertes.map((p) => <option key={p.id} value={p.id}>{p.libelle}</option>)}
          </select>
        </Champ>
      )}
    </Fenetre>
  );
}
