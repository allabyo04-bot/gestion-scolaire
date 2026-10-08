import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useMessage, Chargement, Alerte, Fenetre, Champ } from '../composants/commun.jsx';

const SECOND_CYCLE = ['2NDE', '1ERE', 'TLE'];
const coefTexte = (c) => String(Number(c)).replace('.', ',');

export default function ParamClasses({ ecoleId, classeId }) {
  const classes = useDonnees(() => api.get('param/classes', { ecole_id: ecoleId }), [ecoleId]);
  const niveaux = useDonnees(() => api.get('param/niveaux'), []);
  const series = useDonnees(() => api.get('param/series'), []);
  const profs = useDonnees(() => api.get('param/professeurs', { ecole_id: ecoleId }), [ecoleId]);
  const [edition, setEdition] = useState(null);
  const ref = { niveaux: niveaux.donnees ?? [], series: series.donnees ?? [], profs: profs.donnees ?? [] };

  if (classes.charge && !classes.donnees) return <Chargement />;
  if (classes.erreur) return <Alerte>{classes.erreur}</Alerte>;
  const classe = classeId && classes.donnees?.find((c) => String(c.id) === String(classeId));
  if (classe) return <MatieresClasse classe={classe} classes={classes.donnees} profs={ref.profs} surChangement={classes.recharger} />;

  return (
    <div>
      <div className="entete-avec-action">
        <h2 className="titre-section">Classes de l'année</h2>
        <button className="bouton bouton-principal" onClick={() => setEdition({})}>Créer une classe</button>
      </div>
      {classes.donnees.length === 0 && <div className="vide"><p>Aucune classe pour l'année en cours. Commencez par créer vos classes, puis ajoutez leurs matières.</p></div>}
      {classes.donnees.length > 0 && (
        <div className="tableau-defilant">
          <table className="tableau">
            <thead><tr><th scope="col">Classe</th><th scope="col">Niveau</th><th scope="col">Professeur principal</th>
              <th scope="col" className="nombre">Matières</th><th scope="col" className="nombre">Élèves</th><th scope="col"><span className="visuellement-cache">Actions</span></th></tr></thead>
            <tbody>
              {classes.donnees.map((c) => (
                <tr key={c.id}>
                  <th scope="row"><a href={`#/parametres/classes/${c.id}`}>{c.nom}</a></th>
                  <td>{c.niveau}{c.serie ? ` ${c.serie}` : ''}</td>
                  <td>{c.prof_principal ?? <span className="discret">Non désigné</span>}</td>
                  <td className="nombre">{Number(c.nb_matieres) || <span className="manque">0</span>}</td>
                  <td className="nombre">{c.effectif}</td>
                  <td className="actions-ligne">
                    <a href={`#/parametres/classes/${c.id}`}>Matières</a>
                    <button className="bouton-lien" onClick={() => setEdition(c)}>Modifier</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {edition && <FenetreClasse ecoleId={ecoleId} classe={edition} ref_={ref} surFermer={() => setEdition(null)} surFait={() => { setEdition(null); classes.recharger(); }} />}
    </div>
  );
}

function FenetreClasse({ ecoleId, classe, ref_, surFermer, surFait }) {
  const message = useMessage();
  const [f, setF] = useState({ nom: classe.nom ?? '', niveau_id: classe.niveau_id ?? '', serie_id: classe.serie_id ?? '',
                               prof_principal_id: classe.prof_principal_id ?? '', effectif_max: classe.effectif_max ?? '' });
  const [erreur, setErreur] = useState('');
  const niveau = ref_.niveaux.find((n) => String(n.id) === String(f.niveau_id));
  const avecSerie = niveau && SECOND_CYCLE.includes(niveau.code);
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const envoyer = async () => {
    setErreur('');
    try {
      await api.post('param/classe_enregistrer', { ...f, id: classe.id, ecole_id: ecoleId, serie_id: avecSerie ? f.serie_id : '' });
      message(classe.id ? 'Classe modifiée.' : `Classe ${f.nom} créée.`); surFait();
    } catch (x) { setErreur(x.message); }
  };
  const supprimer = async () => {
    if (!window.confirm(`Supprimer la classe ${classe.nom} ?`)) return;
    try { await api.post('param/classe_supprimer', { id: classe.id }); message('Classe supprimée.'); surFait(); }
    catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={classe.id ? `Modifier ${classe.nom}` : 'Nouvelle classe'} surFermer={surFermer} actions={<>
      {classe.id && <button className="bouton bouton-danger" onClick={supprimer}>Supprimer</button>}
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer}>Enregistrer</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      <div className="deux-colonnes">
        <Champ libelle="Niveau" id="k-niv">
          <select id="k-niv" value={f.niveau_id} onChange={maj('niveau_id')}>
            <option value="">Choisir…</option>
            {['MATERNELLE', 'PRIMAIRE', 'COLLEGE', 'LYCEE'].map((cy) => (
              <optgroup key={cy} label={{ MATERNELLE: 'Maternelle', PRIMAIRE: 'Primaire', COLLEGE: 'Collège', LYCEE: 'Lycée' }[cy]}>
                {ref_.niveaux.filter((n) => n.cycle === cy).map((n) => <option key={n.id} value={n.id}>{n.libelle}</option>)}
              </optgroup>
            ))}
          </select>
        </Champ>
        <Champ libelle="Nom de la classe" id="k-nom" aide="Exemple : 6e A, CM2, Tle D">
          <input id="k-nom" value={f.nom} onChange={maj('nom')} />
        </Champ>
      </div>
      {avecSerie && (
        <Champ libelle="Série" id="k-serie">
          <select id="k-serie" value={f.serie_id} onChange={maj('serie_id')}>
            <option value="">Aucune</option>
            {ref_.series.map((s) => <option key={s.id} value={s.id}>{s.libelle}</option>)}
          </select>
        </Champ>
      )}
      <Champ libelle="Professeur principal" id="k-pp" aide="Il pourra consulter les moyennes et les rangs de sa classe.">
        <select id="k-pp" value={f.prof_principal_id} onChange={maj('prof_principal_id')}>
          <option value="">Non désigné</option>
          {ref_.profs.map((p) => <option key={p.id} value={p.id}>{p.nom} {p.prenoms}</option>)}
        </select>
      </Champ>
      <Champ libelle="Effectif maximum (facultatif)" id="k-max"><input id="k-max" type="number" min="1" value={f.effectif_max} onChange={maj('effectif_max')} /></Champ>
    </Fenetre>
  );
}

function MatieresClasse({ classe, classes, profs, surChangement }) {
  const message = useMessage();
  const liste = useDonnees(() => api.get('param/classe_matieres', { classe_id: classe.id }), [classe.id]);
  const catalogue = useDonnees(() => api.get('param/matieres'), []);
  const [edition, setEdition] = useState(null);
  const [copie, setCopie] = useState(false);
  const recharger = () => { liste.recharger(); surChangement(); };

  const retirer = async (m) => {
    const msg = Number(m.nb_evaluations) ? `${m.libelle} a déjà des évaluations : elle sera masquée, mais les notes sont conservées. Continuer ?` : `Retirer ${m.libelle} de la classe ?`;
    if (!window.confirm(msg)) return;
    try { await api.post('param/classe_matiere_retirer', { id: m.id }); message(`${m.libelle} retirée.`); recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };
  const deplacer = async (index, sens) => {
    const ids = liste.donnees.map((m) => m.id);
    const j = index + sens; if (j < 0 || j >= ids.length) return;
    [ids[index], ids[j]] = [ids[j], ids[index]];
    try { await api.post('param/classe_matieres_ordre', { classe_id: classe.id, ordre: ids }); liste.recharger(); }
    catch (x) { message(x.message, 'erreur'); }
  };
  const totalCoef = (liste.donnees ?? []).reduce((s, m) => s + Number(m.coefficient), 0);
  const sansProf = (liste.donnees ?? []).filter((m) => !m.professeur_id).length;

  return (
    <div>
      <a href="#/parametres/classes" className="retour">Toutes les classes</a>
      <div className="entete-avec-action">
        <div>
          <h2 className="titre-section">Matières de la {classe.nom}</h2>
          {liste.donnees?.length > 0 && <p className="discret">{liste.donnees.length} matières, total des coefficients : {coefTexte(totalCoef)}</p>}
          {sansProf > 0 && <p className="manque">{sansProf === 1 ? 'Une matière attend' : `${sansProf} matières attendent`} un professeur.</p>}
        </div>
        <div className="groupe-boutons">
          {classes.length > 1 && <button className="bouton" onClick={() => setCopie(true)}>Copier d'une autre classe</button>}
          <button className="bouton bouton-principal" onClick={() => setEdition({})}>Ajouter une matière</button>
        </div>
      </div>
      {liste.charge && !liste.donnees && <Chargement />}
      {liste.donnees?.length === 0 && <div className="vide"><p>Aucune matière. Ajoutez-les une à une, ou copiez celles d'une classe du même niveau.</p></div>}
      {liste.donnees?.length > 0 && (
        <div className="tableau-defilant">
          <table className="tableau">
            <thead><tr><th scope="col">Ordre</th><th scope="col">Matière</th><th scope="col" className="nombre">Coef.</th><th scope="col">Professeur</th><th scope="col"><span className="visuellement-cache">Actions</span></th></tr></thead>
            <tbody>
              {liste.donnees.map((m, i) => (
                <tr key={m.id}>
                  <td className="ordre">
                    <button className="fleche" aria-label={`Monter ${m.libelle}`} disabled={i === 0} onClick={() => deplacer(i, -1)}>▲</button>
                    <button className="fleche" aria-label={`Descendre ${m.libelle}`} disabled={i === liste.donnees.length - 1} onClick={() => deplacer(i, 1)}>▼</button>
                  </td>
                  <th scope="row">{m.libelle}{m.groupe_bulletin && <small className="etiquette">{m.groupe_bulletin}</small>}</th>
                  <td className="nombre">{coefTexte(m.coefficient)}</td>
                  <td>{m.professeur ?? <span className="manque">À désigner</span>}</td>
                  <td className="actions-ligne">
                    <button className="bouton-lien" onClick={() => setEdition(m)}>Modifier</button>
                    <button className="bouton-lien" onClick={() => retirer(m)}>Retirer</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="discret texte-explicatif">L'ordre est celui des lignes du bulletin.</p>
      {edition && <FenetreMatiere classe={classe} matiere={edition} catalogue={catalogue} profs={profs} dejaPresentes={(liste.donnees ?? []).map((m) => m.matiere_id)}
                                  surFermer={() => setEdition(null)} surFait={() => { setEdition(null); recharger(); }} />}
      {copie && <FenetreCopie classe={classe} classes={classes} surFermer={() => setCopie(false)} surFait={() => { setCopie(false); recharger(); }} />}
    </div>
  );
}

function FenetreMatiere({ classe, matiere, catalogue, profs, dejaPresentes, surFermer, surFait }) {
  const message = useMessage();
  const nouvelle = !matiere.id;
  const [f, setF] = useState({ matiere_id: matiere.matiere_id ?? '', coefficient: matiere.coefficient ? coefTexte(matiere.coefficient) : '',
                               professeur_id: matiere.professeur_id ?? '', groupe_bulletin: matiere.groupe_bulletin ?? '' });
  const [creation, setCreation] = useState(null);
  const [erreur, setErreur] = useState('');
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const disponibles = (catalogue.donnees ?? []).filter((m) => !nouvelle || !dejaPresentes.includes(m.id));

  const creerMatiere = async () => {
    try {
      const r = await api.post('param/matiere_enregistrer', creation);
      await catalogue.recharger(); setF({ ...f, matiere_id: r.id }); setCreation(null);
    } catch (x) { setErreur(x.message); }
  };
  const envoyer = async () => {
    setErreur('');
    try { await api.post('param/classe_matiere_enregistrer', { ...f, classe_id: classe.id }); message(nouvelle ? 'Matière ajoutée.' : 'Matière modifiée.'); surFait(); }
    catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={nouvelle ? `Ajouter une matière en ${classe.nom}` : `${matiere.libelle} en ${classe.nom}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer} disabled={!f.matiere_id || !f.coefficient}>Enregistrer</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      {nouvelle && !creation && (
        <Champ libelle="Matière" id="m-mat">
          <select id="m-mat" value={f.matiere_id} onChange={maj('matiere_id')}>
            <option value="">Choisir…</option>
            {disponibles.map((m) => <option key={m.id} value={m.id}>{m.libelle}</option>)}
          </select>
          <button type="button" className="bouton-lien" onClick={() => setCreation({ libelle: '', libelle_court: '', est_conduite: false })}>La matière n'est pas dans la liste</button>
        </Champ>
      )}
      {creation && (
        <div className="encart">
          <Champ libelle="Nom complet de la nouvelle matière" id="m-lib"><input id="m-lib" value={creation.libelle} onChange={(e) => setCreation({ ...creation, libelle: e.target.value })} /></Champ>
          <Champ libelle="Nom court pour le bulletin (facultatif)" id="m-court" aide="Exemple : SVT pour Sciences de la vie et de la terre">
            <input id="m-court" value={creation.libelle_court} onChange={(e) => setCreation({ ...creation, libelle_court: e.target.value })} />
          </Champ>
          <label className="case"><input type="checkbox" checked={creation.est_conduite} onChange={(e) => setCreation({ ...creation, est_conduite: e.target.checked })} /> C'est la note de conduite</label>
          <div className="groupe-boutons">
            <button type="button" className="bouton" onClick={() => setCreation(null)}>Annuler</button>
            <button type="button" className="bouton bouton-principal" onClick={creerMatiere} disabled={!creation.libelle.trim()}>Créer la matière</button>
          </div>
        </div>
      )}
      <div className="deux-colonnes">
        <Champ libelle="Coefficient" id="m-coef"><input id="m-coef" inputMode="decimal" value={f.coefficient} onChange={maj('coefficient')} /></Champ>
        <Champ libelle="Groupe sur le bulletin (facultatif)" id="m-grp" aide="Exemple : Matières littéraires">
          <input id="m-grp" value={f.groupe_bulletin} onChange={maj('groupe_bulletin')} />
        </Champ>
      </div>
      <Champ libelle="Professeur" id="m-prof" aide="Lui seul pourra saisir les notes de cette matière dans cette classe.">
        <select id="m-prof" value={f.professeur_id} onChange={maj('professeur_id')}>
          <option value="">À désigner</option>
          {profs.map((p) => <option key={p.id} value={p.id}>{p.nom} {p.prenoms}</option>)}
        </select>
      </Champ>
    </Fenetre>
  );
}

function FenetreCopie({ classe, classes, surFermer, surFait }) {
  const message = useMessage();
  const autres = classes.filter((c) => c.id !== classe.id && Number(c.nb_matieres) > 0);
  const memeNiveau = autres.find((c) => c.niveau_id === classe.niveau_id);
  const [source, setSource] = useState(memeNiveau?.id ?? autres[0]?.id ?? '');
  const [avecProfs, setAvecProfs] = useState(false);
  const [erreur, setErreur] = useState('');
  const envoyer = async () => {
    try {
      const r = await api.post('param/classe_copier_matieres', { source_id: Number(source), classe_id: classe.id, avec_professeurs: avecProfs });
      message(r.copiees ? `${r.copiees} matière(s) copiée(s).` : 'Toutes ces matières existaient déjà.'); surFait();
    } catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={`Copier des matières vers la ${classe.nom}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer} disabled={!source}>Copier</button></>}>
      <p>Les matières et coefficients de la classe choisie sont ajoutés. Les matières déjà présentes ne sont pas modifiées.</p>
      {erreur && <Alerte>{erreur}</Alerte>}
      {autres.length === 0 ? <Alerte type="info">Aucune autre classe n'a encore de matières.</Alerte> : (
        <>
          <Champ libelle="Copier depuis" id="cp-src">
            <select id="cp-src" value={source} onChange={(e) => setSource(e.target.value)}>
              {autres.map((c) => <option key={c.id} value={c.id}>{c.nom} ({c.nb_matieres} matières)</option>)}
            </select>
          </Champ>
          <label className="case"><input type="checkbox" checked={avecProfs} onChange={(e) => setAvecProfs(e.target.checked)} /> Reprendre aussi les mêmes professeurs</label>
        </>
      )}
    </Fenetre>
  );
}
