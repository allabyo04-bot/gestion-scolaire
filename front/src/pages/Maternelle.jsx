import { useEffect, useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useMessage, Chargement, Alerte, Champ } from '../composants/commun.jsx';

// Maternelle : grille trimestrielle d'observation et d'appréciation (11 domaines, TS / S / PS)
const NIVEAUX = [['TS', 'Très satisfaisant'], ['S', 'Satisfaisant'], ['PS', 'Peu satisfaisant']];
const nomTrim = (t) => `${Number(t) === 1 ? '1er' : `${t}e`} trimestre`;

export default function Maternelle({ classeId, trimestre }) {
  const t = Number(trimestre) || 1;
  const message = useMessage();
  const d = useDonnees(() => api.get('mat/grille', { classe_id: classeId, trimestre: t }), [classeId, t]);
  const [index, setIndex] = useState(0);
  const [grilles, setGrilles] = useState({});
  const [modifie, setModifie] = useState(false);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    if (!d.donnees) return;
    const g = {};
    for (const e of d.donnees.eleves) g[e.inscription_id] = { valeurs: [...e.valeurs], observation: e.observation ?? '' };
    setGrilles(g); setModifie(false); setErreur('');
  }, [d.donnees]);

  if (d.charge) return <Chargement />;
  if (d.erreur) return <><a href="#/accueil" className="retour">Notes</a><Alerte>{d.erreur}</Alerte></>;
  const m = d.donnees;
  if (!m.eleves.length) return <><a href="#/accueil" className="retour">Notes</a><div className="vide"><p>Aucun enfant inscrit dans cette classe.</p></div></>;
  const e = m.eleves[Math.min(index, m.eleves.length - 1)];
  const g = grilles[e.inscription_id] ?? { valeurs: Array(m.domaines.length).fill(''), observation: '' };
  const completes = m.eleves.filter((x) => (grilles[x.inscription_id]?.valeurs ?? []).every((v) => v)).length;

  const choisir = (i, v) => { const valeurs = [...g.valeurs]; valeurs[i] = valeurs[i] === v ? '' : v; setGrilles({ ...grilles, [e.inscription_id]: { ...g, valeurs } }); setModifie(true); };
  const enregistrer = async (suivant) => {
    setOccupe(true); setErreur('');
    try {
      await api.post('mat/enregistrer', { classe_id: classeId, trimestre: t, grilles: [{ inscription_id: e.inscription_id, ...g }] });
      setModifie(false); message(`Grille de ${e.prenoms} enregistrée.`);
      if (suivant && index < m.eleves.length - 1) setIndex(index + 1);
    } catch (x) { setErreur(x.message); }
    finally { setOccupe(false); }
  };
  const changerEleve = (i) => { if (modifie) { setErreur("Enregistrez d'abord la grille de cet enfant."); return; } setErreur(''); setIndex(i); };

  return (
    <section>
      <a href="#/accueil" className="retour">Notes</a>
      <div className="entete-page entete-avec-action">
        <div>
          <h1>{m.classe.nom} — Grilles d'observation</h1>
          <p>{nomTrim(t)} · {completes} grille(s) complète(s) sur {m.eleves.length} · l'enfant est évalué par rapport à lui-même.</p>
        </div>
        <div className="groupe-boutons">
          <a className="bouton bouton-principal" href={`#/maternelle-livrets/${classeId}/${t}`}>Imprimer les grilles</a>
        </div>
      </div>
      <nav className="sous-onglets" aria-label="Trimestre">
        {m.periodes.map((p) => (
          <a key={p.id} href={`#/maternelle/${classeId}/${p.numero}`} className={p.numero === t ? 'actif' : ''} aria-current={p.numero === t ? 'page' : undefined}>{p.libelle}</a>
        ))}
      </nav>
      {!m.modifiable && <Alerte type="info">{m.periode.statut !== 'OUVERTE' ? 'Ce trimestre est clôturé : consultation seulement.' : 'Consultation seulement : seuls le titulaire et la direction modifient les grilles.'}</Alerte>}
      {erreur && <Alerte>{erreur}</Alerte>}

      <div className="barre-recherche">
        <button className="bouton" onClick={() => changerEleve(index - 1)} disabled={index === 0}>Précédent</button>
        <select aria-label="Enfant" value={index} onChange={(ev) => changerEleve(Number(ev.target.value))}>
          {m.eleves.map((x, i) => <option key={x.inscription_id} value={i}>{x.nom} {x.prenoms}{(grilles[x.inscription_id]?.valeurs ?? []).every((v) => v) ? ' ✓' : ''}</option>)}
        </select>
        <button className="bouton" onClick={() => changerEleve(index + 1)} disabled={index >= m.eleves.length - 1}>Suivant</button>
      </div>

      <div className="tableau-defilant">
        <table className="tableau grille-maternelle">
          <thead><tr><th scope="col">N°</th><th scope="col" className="g">Résultats obtenus — {e.nom} {e.prenoms}</th>{NIVEAUX.map(([c, l]) => <th key={c} scope="col" title={l}>{c}</th>)}</tr></thead>
          <tbody>
            {m.domaines.map((dom, i) => (
              <tr key={dom}>
                <td>{i + 1}</td>
                <th scope="row" className="g">{dom}</th>
                {NIVEAUX.map(([c, l]) => (
                  <td key={c}>
                    <button type="button" className={`choix-appreciation${g.valeurs[i] === c ? ' choisi' : ''}`} aria-pressed={g.valeurs[i] === c}
                            aria-label={`${dom} : ${l}`} disabled={!m.modifiable} onClick={() => choisir(i, c)}>{g.valeurs[i] === c ? 'X' : ''}</button>
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="discret">TS : très satisfaisant · S : satisfaisant · PS : peu satisfaisant. Cliquez à nouveau sur une case pour l'effacer.</p>
      <Champ libelle="Observation de l'enseignant (facultatif)" id="m-obs">
        <textarea id="m-obs" rows={2} maxLength={500} value={g.observation} disabled={!m.modifiable}
                  onChange={(ev) => { setGrilles({ ...grilles, [e.inscription_id]: { ...g, observation: ev.target.value } }); setModifie(true); }} />
      </Champ>
      {m.modifiable && (
        <div className="groupe-boutons">
          <button className="bouton" onClick={() => enregistrer(false)} disabled={occupe}>Enregistrer</button>
          <button className="bouton bouton-principal" onClick={() => enregistrer(true)} disabled={occupe || index >= m.eleves.length - 1}>Enregistrer et passer au suivant</button>
        </div>
      )}
    </section>
  );
}

// Grilles imprimables (une page A4 par enfant)
export function MaternelleLivrets({ classeId, trimestre }) {
  const t = Number(trimestre) || 1;
  const d = useDonnees(() => api.get('mat/grille', { classe_id: classeId, trimestre: t }), [classeId, t]);
  if (d.charge) return <Chargement />;
  if (d.erreur) return <><a href="#/classes" className="retour">Classes</a><Alerte>{d.erreur}</Alerte></>;
  const m = d.donnees, ec = m.ecole, img = ec.images ?? {};
  return (
    <section>
      <div className="pas-imprimer">
        <a href={`#/maternelle/${classeId}/${t}`} className="retour">Grilles</a>
        <div className="entete-page entete-avec-action">
          <div><h1>Grilles d'observation {m.classe.nom}</h1><p>{m.periode.libelle}, {m.eleves.length} enfant(s).</p></div>
          <button className="bouton bouton-principal" onClick={() => window.print()}>Imprimer les {m.eleves.length} grilles</button>
        </div>
      </div>
      <div className="pile-bulletins">
        {m.eleves.map((e) => {
          const compte = { TS: 0, S: 0, PS: 0 };
          e.valeurs.forEach((v) => { if (compte[v] !== undefined) compte[v]++; });
          const naissance = [e.date_naissance && e.date_naissance.split('-').reverse().join('/'), e.lieu_naissance].filter(Boolean).join(' à ');
          return (
            <article key={e.inscription_id} className="bulletin bul-primaire">
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
              <p className="bul-bandeau">GRILLE TRIMESTRIELLE D'OBSERVATION ET D'APPRÉCIATION — {m.periode.libelle.toUpperCase()} — {m.classe.annee}</p>
              <div className="bp-identite">
                <p><span className="bul-etiq">Nom et prénoms :</span> <strong>{e.nom} {e.prenoms}</strong></p>
                <p><span className="bul-etiq">Section :</span> <strong>{m.classe.nom}</strong></p>
                <p><span className="bul-etiq">Né(e) le :</span> {naissance || '……………'}</p>
                <p><span className="bul-etiq">Titulaire :</span> {m.classe.titulaire ?? '……………'}</p>
              </div>
              <table className="bul-notes bp-grille">
                <thead><tr><th>N°</th><th className="g">Résultats obtenus</th>{NIVEAUX.map(([c]) => <th key={c}>{c}</th>)}</tr></thead>
                <tbody>
                  {m.domaines.map((dom, i) => (
                    <tr key={dom}><td>{i + 1}</td><td className="g">{dom}</td>{NIVEAUX.map(([c]) => <td key={c} className="fort">{e.valeurs[i] === c ? 'X' : ''}</td>)}</tr>
                  ))}
                  <tr className="bul-totaux"><td /><td className="g">Récapitulatif</td>{NIVEAUX.map(([c]) => <td key={c}>{compte[c]}</td>)}</tr>
                </tbody>
              </table>
              <p className="bp-note">TS : très satisfaisant · S : satisfaisant · PS : peu satisfaisant. L'évaluation est faite sur la base de l'observation permanente de l'enfant. L'enfant est évalué par rapport à lui-même et non par rapport à ses camarades de classe.</p>
              <div className="bp-appreciation">
                <p className="bul-titre-case">Observation de l'enseignant</p>
                {e.observation ? <p>{e.observation}</p> : <><p className="bul-ligne" /><p className="bul-ligne" /></>}
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
        })}
      </div>
    </section>
  );
}
