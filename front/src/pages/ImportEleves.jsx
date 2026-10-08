import { useState } from 'react';
import { api } from '../api.js';
import { useMessage, Alerte, Fenetre, Champ } from '../composants/commun.jsx';
import { lireFichier, lireTexteColle } from '../composants/lectureListe.js';

const STATUTS = {
  NOUVEAU: ['Nouveau', 'statut-nouveau'],
  REINSCRIPTION: ['Déjà connu, réinscrit', 'statut-reinscription'],
  DEJA_INSCRIT: ['Déjà inscrit, ignoré', 'statut-ignore'],
  ERREUR: ['Erreur, ignoré', 'statut-erreur'],
};

export default function ImportEleves({ classes, classeParDefaut, surFermer, surFait }) {
  const message = useMessage();
  const [classeId, setClasseId] = useState(classeParDefaut || '');
  const [mode, setMode] = useState('fichier');
  const [texte, setTexte] = useState('');
  const [lus, setLus] = useState(null);           // élèves lus dans le fichier
  const [apercu, setApercu] = useState(null);     // réponse du serveur
  const [erreur, setErreur] = useState('');
  const [travail, setTravail] = useState(false);

  const analyser = async (eleves) => {
    setErreur(''); setTravail(true);
    try {
      if (!eleves.length) throw new Error('Aucun élève trouvé dans la liste.');
      setLus(eleves);
      setApercu(await api.post('eleves/importer', { classe_id: Number(classeId), lignes: eleves }));
    } catch (x) { setErreur(x.message); setApercu(null); }
    finally { setTravail(false); }
  };
  const depuisFichier = async (e) => {
    const f = e.target.files?.[0]; if (!f) return;
    setTravail(true);
    try { analyser((await lireFichier(f)).eleves); } catch (x) { setErreur(x.message); setTravail(false); }
    e.target.value = '';
  };
  const depuisTexte = () => {
    try { analyser(lireTexteColle(texte).eleves); } catch (x) { setErreur(x.message); }
  };
  const importer = async () => {
    setTravail(true);
    try {
      const r = await api.post('eleves/importer', { classe_id: Number(classeId), lignes: lus, confirmer: true });
      message(`${r.importes} élève(s) importé(s) en ${r.classe}.`);
      surFait(Number(classeId));
    } catch (x) { setErreur(x.message); setTravail(false); }
  };

  const c = apercu?.compte ?? {};
  const aImporter = (c.NOUVEAU ?? 0) + (c.REINSCRIPTION ?? 0);
  const classe = classes.find((x) => String(x.id) === String(classeId));

  return (
    <Fenetre titre={apercu ? `Aperçu de l'import en ${apercu.classe}` : 'Importer une liste d\'élèves'} surFermer={surFermer} large
      actions={apercu ? <>
        <button className="bouton" onClick={() => { setApercu(null); setLus(null); }} disabled={travail}>Changer de liste</button>
        <button className="bouton bouton-principal" onClick={importer} disabled={travail || !aImporter}>
          {travail ? 'Import en cours…' : `Importer ${aImporter} élève${aImporter > 1 ? 's' : ''}`}</button></> : <>
        <button className="bouton" onClick={surFermer}>Annuler</button>
        {mode === 'colle' && <button className="bouton bouton-principal" onClick={depuisTexte} disabled={!classeId || !texte.trim() || travail}>Vérifier la liste</button>}</>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      {!apercu ? (
        <>
          <p>Importez une classe à la fois. Rien n'est enregistré avant votre confirmation : un aperçu vous montre d'abord chaque élève et les erreurs éventuelles.</p>
          <Champ libelle="Classe de destination" id="imp-classe">
            <select id="imp-classe" value={classeId} onChange={(e) => setClasseId(e.target.value)}>
              <option value="">Choisir…</option>
              {classes.map((x) => <option key={x.id} value={x.id}>{x.nom}</option>)}
            </select>
          </Champ>
          <div className="choix-periode" role="tablist" aria-label="Source de la liste">
            <button role="tab" aria-selected={mode === 'fichier'} onClick={() => setMode('fichier')}>Fichier Excel ou CSV</button>
            <button role="tab" aria-selected={mode === 'colle'} onClick={() => setMode('colle')}>Copier-coller depuis Word</button>
          </div>
          {mode === 'fichier' ? (
            <Champ libelle="Fichier" id="imp-fichier" aide="Formats acceptés : .xlsx, .xls, .csv. La ligne d'en-tête doit contenir les colonnes Nom et Prénom(s), ou Nom et prénoms.">
              <input id="imp-fichier" type="file" accept=".xlsx,.xls,.csv" disabled={!classeId || travail} onChange={depuisFichier} />
            </Champ>
          ) : (
            <Champ libelle="Tableau copié" id="imp-texte" aide="Dans Word, sélectionnez tout le tableau de la classe, y compris sa ligne d'en-tête, faites Ctrl+C, puis collez ici avec Ctrl+V.">
              <textarea id="imp-texte" rows={8} value={texte} onChange={(e) => setTexte(e.target.value)} placeholder="N°   NOM ET PRÉNOMS   SEXE   NUMERO EDUCMASTER" />
            </Champ>
          )}
          {travail && <p className="discret">Lecture de la liste…</p>}
          {!classeId && <p className="discret">Choisissez d'abord la classe.</p>}
        </>
      ) : (
        <>
          <div className="resume-import">
            <span className="statut-nouveau">{c.NOUVEAU ?? 0} nouveaux</span>
            {c.REINSCRIPTION ? <span className="statut-reinscription">{c.REINSCRIPTION} déjà connus</span> : null}
            {c.DEJA_INSCRIT ? <span className="statut-ignore">{c.DEJA_INSCRIT} déjà inscrits</span> : null}
            {c.ERREUR ? <span className="statut-erreur">{c.ERREUR} en erreur</span> : null}
          </div>
          {c.ERREUR > 0 && <Alerte type="info">Les lignes en erreur ne seront pas importées. Vous pourrez corriger la liste et la réimporter : les élèves déjà importés seront reconnus et ignorés.</Alerte>}
          {classe && Number(classe.effectif) > 0 && <p className="discret">La {classe.nom} compte déjà {classe.effectif} élève(s).</p>}
          <div className="tableau-defilant apercu-import">
            <table className="tableau">
              <thead><tr><th scope="col" className="nombre">N°</th><th scope="col">Élève</th><th scope="col">Résultat</th></tr></thead>
              <tbody>
                {apercu.lignes.map((l) => (
                  <tr key={l.ligne} className={l.statut === 'ERREUR' ? 'ligne-erreur' : ''}>
                    <td className="nombre">{l.ligne}</td>
                    <th scope="row">
                      {l.nom} {l.prenoms}
                      <small className="sous-ligne">
                        {[l.sexe === 'F' ? 'Fille' : l.sexe === 'M' ? 'Garçon' : 'Sexe ?', l.educmaster,
                          l.date_naissance && `née le ${l.date_naissance.split('-').reverse().join('/')}`.replace('née', l.sexe === 'M' ? 'né' : 'née'),
                          l.lieu_naissance].filter(Boolean).join(', ')}
                      </small>
                    </th>
                    <td>
                      <span className={STATUTS[l.statut][1]}>{STATUTS[l.statut][0]}</span>
                      {[...l.erreurs, ...l.avertissements].map((m) => <small key={m} className="note-ligne">{m}</small>)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Fenetre>
  );
}
