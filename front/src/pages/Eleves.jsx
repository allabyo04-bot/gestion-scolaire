import { useEffect, useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useSession, useMessage, Chargement, Alerte, Fenetre, Champ, aller } from '../composants/commun.jsx';
import ChoixEcole from '../composants/ChoixEcole.jsx';
import ImportEleves from './ImportEleves.jsx';

export const STATUTS_INSC = { ACTIF: 'Inscrit', TRANSFERE: 'Transféré', ABANDON: 'Abandon', EXCLU: 'Exclu' };
// 1er chiffre du numéro Educmaster : 1 = garçon, 2 = fille
export const incoherence = (num, sexe) => {
  const n = String(num ?? '').replace(/\s/g, '');
  if (!/^\d{12,13}$/.test(n) || !sexe) return null;
  const attendu = n[0] === '1' ? 'M' : n[0] === '2' ? 'F' : null;
  return attendu && attendu !== sexe ? `Le numéro Educmaster commence par ${n[0]}, ce qui correspond à ${attendu === 'M' ? 'un garçon' : 'une fille'}. Vérifiez.` : null;
};
export const LIENS = { PERE: 'Père', MERE: 'Mère', TUTEUR: 'Tuteur', AUTRE: 'Autre' };
// 0197451230 → 01 97 45 12 30
export const formatTel = (t) => t && /^\d{8,10}$/.test(t) ? t.replace(/(\d{2})(?=\d)/g, '$1 ') : t;
export const age = (d) => {
  if (!d) return null;
  const n = new Date(d), a = new Date();
  return a.getFullYear() - n.getFullYear() - (a < new Date(a.getFullYear(), n.getMonth(), n.getDate()) ? 1 : 0);
};

export default function Eleves({ classeId }) {
  const { utilisateur } = useSession();
  const [ecoleId, setEcoleId] = useState(utilisateur.ecole?.id ?? null);
  const classes = useDonnees(() => ecoleId ? api.get('ref/classes', { ecole_id: ecoleId }) : Promise.resolve(null), [ecoleId]);
  const [q, setQ] = useState('');
  const [inscrire, setInscrire] = useState(false);
  const [importer, setImporter] = useState(false);
  const choisie = classeId ?? '';

  return (
    <section>
      <div className="entete-page entete-avec-action">
        <div><h1>Élèves</h1><p>Inscriptions de l'année en cours, fiches et tuteurs.</p></div>
        <div className="groupe-boutons">
          <button className="bouton" onClick={() => setImporter(true)} disabled={!classes.donnees?.length}>Importer une liste</button>
          <button className="bouton bouton-principal" onClick={() => setInscrire(true)} disabled={!classes.donnees?.length}>Inscrire un élève</button>
        </div>
      </div>
      <ChoixEcole valeur={ecoleId} surChangement={setEcoleId} />
      <input className="recherche" type="search" placeholder="Rechercher par nom, matricule ou numéro Educmaster"
             aria-label="Rechercher un élève" value={q} onChange={(e) => setQ(e.target.value)} />
      {q.trim().length >= 2 ? <Recherche q={q.trim()} ecoleId={ecoleId} /> : (
        <>
          {classes.charge && <Chargement />}
          {classes.donnees?.length === 0 && <div className="vide"><p>Aucune classe pour l'année en cours. La direction doit d'abord créer les classes dans Paramètres.</p></div>}
          {classes.donnees?.length > 0 && (
            <div className="puces-classes" role="tablist" aria-label="Classe">
              {classes.donnees.map((c) => (
                <button key={c.id} role="tab" aria-selected={String(c.id) === String(choisie)} onClick={() => aller('eleves', c.id)}>
                  {c.nom}<small>{c.effectif}</small>
                </button>
              ))}
            </div>
          )}
          {choisie ? <ListeClasse classeId={choisie} /> : classes.donnees?.length > 0 && <p className="discret">Choisissez une classe pour voir ses élèves.</p>}
        </>
      )}
      {importer && <ImportEleves classes={classes.donnees ?? []} classeParDefaut={choisie} surFermer={() => setImporter(false)}
                    surFait={(cid) => { setImporter(false); classes.recharger(); aller('eleves', cid); window.dispatchEvent(new HashChangeEvent('hashchange')); }} />}
      {inscrire && <FenetreInscription classes={classes.donnees ?? []} classeParDefaut={choisie}
                    surFermer={() => setInscrire(false)}
                    surFait={(r, cid) => { setInscrire(false); classes.recharger(); aller('eleve', r.eleve_id); }} />}
    </section>
  );
}

function TableEleves({ eleves, avecClasse }) {
  return (
    <div className="tableau-defilant">
      <table className="tableau tableau-eleves">
        <thead><tr><th scope="col">Élève</th>{avecClasse && <th scope="col">Classe</th>}<th scope="col">Educmaster</th><th scope="col">Tuteur</th></tr></thead>
        <tbody>
          {eleves.map((e) => (
            <tr key={e.id} className={e.statut !== 'ACTIF' ? 'inactif' : ''}>
              <th scope="row">
                <a href={`#/eleve/${e.id}`}>{e.nom} {e.prenoms}</a>
                <small className="sous-ligne">{e.matricule}{Number(e.redoublant) ? ', redoublant' + (e.sexe === 'F' ? 'e' : '') : ''}</small>
                {e.statut !== 'ACTIF' && <small className="etiquette">{STATUTS_INSC[e.statut]}</small>}
              </th>
              {avecClasse && <td>{e.classe}</td>}
              <td className="nombre-gauche">{e.educmaster ?? <span className="discret">—</span>}</td>
              <td>{e.telephone_tuteur ? <a href={`tel:${e.telephone_tuteur}`}>{formatTel(e.telephone_tuteur)}</a> : <span className="manque">Aucun</span>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ListeClasse({ classeId }) {
  const l = useDonnees(() => api.get('eleves/liste', { classe_id: classeId }), [classeId]);
  if (l.charge) return <Chargement />;
  if (l.erreur) return <Alerte>{l.erreur}</Alerte>;
  if (!l.donnees.length) return <div className="vide"><p>Aucun élève inscrit dans cette classe.</p></div>;
  const actifs = l.donnees.filter((e) => e.statut === 'ACTIF');
  const f = actifs.filter((e) => e.sexe === 'F').length;
  return (
    <>
      <p className="resume-classe">{actifs.length} élèves : {f} fille{f > 1 ? 's' : ''} et {actifs.length - f} garçon{actifs.length - f > 1 ? 's' : ''}</p>
      <TableEleves eleves={l.donnees} />
    </>
  );
}

function Recherche({ q, ecoleId }) {
  const [terme, setTerme] = useState(q);
  useEffect(() => { const t = setTimeout(() => setTerme(q), 300); return () => clearTimeout(t); }, [q]);
  const r = useDonnees(() => api.get('eleves/liste', { q: terme, ecole_id: ecoleId }), [terme, ecoleId]);
  if (r.charge) return <Chargement texte="Recherche…" />;
  if (r.erreur) return <Alerte>{r.erreur}</Alerte>;
  if (!r.donnees.length) return <div className="vide"><p>Aucun élève inscrit cette année ne correspond à « {terme} ».</p></div>;
  return <TableEleves eleves={r.donnees} avecClasse />;
}

// ------------------------------------------------------------------ Inscription
function FenetreInscription({ classes, classeParDefaut, surFermer, surFait }) {
  const message = useMessage();
  const [etape, setEtape] = useState('educmaster');      // educmaster → formulaire
  const [educ, setEduc] = useState('');
  const [existant, setExistant] = useState(null);
  const [f, setF] = useState({ nom: '', prenoms: '', sexe: '', date_naissance: '', lieu_naissance: '', nationalite: 'Béninoise',
                               classe_id: classeParDefaut || '', redoublant: false });
  const [t, setT] = useState({ nom: '', prenoms: '', telephone: '', lien: 'PERE' });
  const [erreur, setErreur] = useState('');
  const [homonyme, setHomonyme] = useState(false);
  const maj = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

  const verifier = async () => {
    setErreur('');
    if (!educ.trim()) { setEtape('formulaire'); return; }
    if (!/^\d{12,13}$/.test(educ.replace(/\s/g, ''))) { setErreur('Le numéro Educmaster comporte 12 ou 13 chiffres.'); return; }
    try {
      const r = await api.get('eleves/chercher_educmaster', { educmaster: educ.replace(/\s/g, '') });
      setExistant(r); setEtape('formulaire');
    } catch (x) { setErreur(x.message); }
  };
  const envoyer = async () => {
    setErreur('');
    const tuteurs = t.nom.trim() || t.telephone.trim() ? [{ ...t, principal: true }] : [];
    const donnees = existant ? { eleve_id: existant.id, classe_id: f.classe_id, redoublant: f.redoublant, tuteurs }
                             : { ...f, educmaster: educ.replace(/\s/g, ''), tuteurs, confirmer_homonyme: homonyme };
    try {
      const r = await api.post('eleves/inscrire', donnees);
      if (r.avertissement) message(r.avertissement, 'erreur');
      message(`${existant ? existant.nom + ' ' + existant.prenoms : f.nom.toUpperCase() + ' ' + f.prenoms} inscrit${(existant?.sexe ?? f.sexe) === 'F' ? 'e' : ''}. Matricule ${r.matricule}.`);
      surFait(r, f.classe_id);
    } catch (x) {
      setErreur(x.message);
      if (x.message.includes('existe déjà')) setHomonyme(true);
    }
  };

  const dejaInscrit = existant && Number(existant.derniere_inscription?.en_cours);
  const formulaireOk = !dejaInscrit && f.classe_id && (existant || (f.nom.trim() && f.prenoms.trim() && f.sexe));
  return (
    <Fenetre titre="Inscrire un élève" surFermer={surFermer} actions={etape === 'educmaster' ? <>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={verifier}>{educ.trim() ? 'Vérifier le numéro' : 'Continuer sans numéro'}</button></> : <>
      <button className="bouton" onClick={() => { setEtape('educmaster'); setExistant(null); setErreur(''); }}>Retour</button>
      <button className="bouton bouton-principal" onClick={envoyer} disabled={!formulaireOk}>{homonyme ? 'Inscrire quand même' : 'Inscrire'}</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      {etape === 'educmaster' ? (
        <Champ libelle="Numéro Educmaster" id="i-educ" aide="12 ou 13 chiffres. Laissez vide si l'élève n'en a pas encore : le numéro pourra être ajouté plus tard.">
          <input id="i-educ" inputMode="numeric" autoFocus value={educ} onChange={(e) => setEduc(e.target.value.replace(/[^\d\s]/g, ''))}
                 onKeyDown={(e) => e.key === 'Enter' && verifier()} />
        </Champ>
      ) : (
        <>
          {existant && Number(existant.derniere_inscription?.en_cours) ? (
            <Alerte type="info" action={<a className="bouton bouton-discret" href={`#/eleve/${existant.id}`} onClick={surFermer}>Ouvrir sa fiche</a>}>
              <strong>{existant.nom} {existant.prenoms}</strong> est déjà inscrit{existant.sexe === 'F' ? 'e' : ''} cette année,
              en {existant.derniere_inscription.classe} ({existant.derniere_inscription.ecole}).
              Pour changer de classe, passez par sa fiche.
            </Alerte>
          ) : existant && (
            <Alerte type="info">
              Élève déjà connu dans le réseau : <strong>{existant.nom} {existant.prenoms}</strong>
              {existant.derniere_inscription && <>, dernière inscription en {existant.derniere_inscription.classe} ({existant.derniere_inscription.ecole}, {existant.derniere_inscription.annee})</>}.
              Son dossier sera repris.
            </Alerte>
          )}
          {!existant && educ.trim() && <p className="discret">Numéro {educ} : aucun élève trouvé, il s'agit d'une première inscription dans le réseau.</p>}
          {!existant && (
            <>
              <div className="deux-colonnes">
                <Champ libelle="Nom" id="i-nom"><input id="i-nom" value={f.nom} onChange={maj('nom')} autoCapitalize="characters" /></Champ>
                <Champ libelle="Prénoms" id="i-pre"><input id="i-pre" value={f.prenoms} onChange={maj('prenoms')} /></Champ>
              </div>
              <div className="deux-colonnes">
                <Champ libelle="Sexe" id="i-sexe">
                  <select id="i-sexe" value={f.sexe} onChange={maj('sexe')}><option value="">Choisir…</option><option value="F">Fille</option><option value="M">Garçon</option></select>
                </Champ>
                <Champ libelle="Date de naissance" id="i-ddn"><input id="i-ddn" type="date" value={f.date_naissance} onChange={maj('date_naissance')} /></Champ>
              </div>
              {incoherence(educ, f.sexe) && <p className="avertissement-champ">{incoherence(educ, f.sexe)}</p>}
              <div className="deux-colonnes">
                <Champ libelle="Lieu de naissance" id="i-lieu"><input id="i-lieu" value={f.lieu_naissance} onChange={maj('lieu_naissance')} /></Champ>
                <Champ libelle="Nationalité" id="i-nat"><input id="i-nat" value={f.nationalite} onChange={maj('nationalite')} /></Champ>
              </div>
            </>
          )}
          {!(existant && Number(existant.derniere_inscription?.en_cours)) && <>
          <div className="deux-colonnes">
            <Champ libelle="Classe" id="i-classe">
              <select id="i-classe" value={f.classe_id} onChange={maj('classe_id')}>
                <option value="">Choisir…</option>
                {classes.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}
              </select>
            </Champ>
            <label className="case case-alignee"><input type="checkbox" checked={f.redoublant} onChange={maj('redoublant')} /> Redouble cette classe</label>
          </div>
          {existant ? <p className="discret">Ses tuteurs déjà enregistrés sont conservés. Vous pourrez les compléter depuis sa fiche.</p> : (
          <fieldset className="groupe-champs">
            <legend>Tuteur principal</legend>
            <div className="deux-colonnes">
              <Champ libelle="Nom" id="t-nom"><input id="t-nom" value={t.nom} onChange={(e) => setT({ ...t, nom: e.target.value })} /></Champ>
              <Champ libelle="Prénoms" id="t-pre"><input id="t-pre" value={t.prenoms} onChange={(e) => setT({ ...t, prenoms: e.target.value })} /></Champ>
            </div>
            <div className="deux-colonnes">
              <Champ libelle="Téléphone" id="t-tel" aide="Servira à l'espace parents."><input id="t-tel" type="tel" value={t.telephone} onChange={(e) => setT({ ...t, telephone: e.target.value })} /></Champ>
              <Champ libelle="Lien avec l'élève" id="t-lien">
                <select id="t-lien" value={t.lien} onChange={(e) => setT({ ...t, lien: e.target.value })}>
                  {Object.entries(LIENS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Champ>
            </div>
          </fieldset>)}
          </>}
        </>
      )}
    </Fenetre>
  );
}
