import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useMessage, Chargement, Alerte, Fenetre, Champ } from '../composants/commun.jsx';
import { fcfa } from '../composants/montants.js';

const dateFr = (d) => d ? d.split('-').reverse().join('/') : 'aucune';

export default function ParamFrais({ ecoleId }) {
  const t = useDonnees(() => api.get('fin/tarifs', { ecole_id: ecoleId }), [ecoleId]);
  const [edition, setEdition] = useState(null);
  if (t.charge && !t.donnees) return <Chargement />;
  if (t.erreur) return <Alerte>{t.erreur}</Alerte>;
  return (
    <div>
      <h2 className="titre-section">Frais de scolarité {t.donnees.annee}</h2>
      <p className="discret texte-explicatif">Un tarif par niveau, découpé en tranches. Une tranche dont l'échéance est passée et qui n'est pas réglée apparaît « en retard » dans la caisse et les impayés.</p>
      {t.donnees.niveaux.length === 0 && <div className="vide"><p>Créez d'abord les classes de l'école.</p></div>}
      <div className="liste-tarifs">
        {t.donnees.niveaux.map((n) => (
          <article key={n.id} className="carte-fiche">
            <div className="carte-fiche-titre">
              <h2>{n.libelle}</h2>
              <button className="bouton-lien" onClick={() => setEdition(n)}>{n.tarif ? 'Modifier' : 'Fixer le tarif'}</button>
            </div>
            {n.tarif ? (
              <>
                <p className="tarif-total">{fcfa(n.tarif.montant)}</p>
                {n.tarif.observation && <p className="discret">{n.tarif.observation}</p>}
                <ul className="liste-tranches">
                  {n.tarif.tranches.map((x) => <li key={x.id}><span>{x.libelle}</span><span className="chiffres">{fcfa(x.montant)}</span><small>échéance : {dateFr(x.echeance)}</small></li>)}
                </ul>
              </>
            ) : <p className="manque">Aucun tarif : la caisse ne peut pas encaisser pour ce niveau.</p>}
          </article>
        ))}
      </div>
      {edition && <FenetreTarif ecoleId={ecoleId} niveau={edition} surFermer={() => setEdition(null)} surFait={() => { setEdition(null); t.recharger(); }} />}
    </div>
  );
}

function FenetreTarif({ ecoleId, niveau, surFermer, surFait }) {
  const message = useMessage();
  const [tranches, setTranches] = useState(niveau.tarif?.tranches.map((x) => ({ libelle: x.libelle, montant: String(x.montant), echeance: x.echeance ?? '' }))
                                            ?? [{ libelle: 'Scolarité', montant: '', echeance: '' }]);
  const [obs, setObs] = useState(niveau.tarif?.observation ?? '');
  const [erreur, setErreur] = useState('');
  const total = tranches.reduce((s, x) => s + (Number(String(x.montant).replace(/\s/g, '')) || 0), 0);
  const maj = (k, cle, v) => setTranches(tranches.map((x, j) => (j === k ? { ...x, [cle]: v } : x)));
  const envoyer = async () => {
    try { await api.post('fin/tarif_enregistrer', { ecole_id: ecoleId, niveau_id: niveau.id, tranches, observation: obs }); message(`Tarif de ${niveau.libelle} enregistré : ${fcfa(total)}.`); surFait(); }
    catch (x) { setErreur(x.message); }
  };
  return (
    <Fenetre titre={`Tarif ${niveau.libelle}`} surFermer={surFermer} actions={<>
      <button className="bouton" onClick={surFermer}>Annuler</button>
      <button className="bouton bouton-principal" onClick={envoyer} disabled={!total}>Enregistrer ({fcfa(total)})</button></>}>
      {erreur && <Alerte>{erreur}</Alerte>}
      {tranches.map((x, k) => (
        <fieldset key={k} className="groupe-champs">
          <legend>Tranche {k + 1}</legend>
          <Champ libelle="Libellé" id={`t-l${k}`}><input id={`t-l${k}`} value={x.libelle} onChange={(e) => maj(k, 'libelle', e.target.value)} /></Champ>
          <div className="deux-colonnes">
            <Champ libelle="Montant (F CFA)" id={`t-m${k}`}><input id={`t-m${k}`} inputMode="numeric" value={x.montant} onChange={(e) => maj(k, 'montant', e.target.value)} /></Champ>
            <Champ libelle="Échéance (facultatif)" id={`t-e${k}`}><input id={`t-e${k}`} type="date" value={x.echeance} onChange={(e) => maj(k, 'echeance', e.target.value)} /></Champ>
          </div>
          {tranches.length > 1 && <button type="button" className="bouton-lien" onClick={() => setTranches(tranches.filter((_, j) => j !== k))}>Retirer cette tranche</button>}
        </fieldset>
      ))}
      <button type="button" className="bouton bouton-discret" onClick={() => setTranches([...tranches, { libelle: `${tranches.length + 1}e tranche`, montant: '', echeance: '' }])}>Ajouter une tranche</button>
      <Champ libelle="Observation (facultatif)" id="t-obs"><input id="t-obs" placeholder="Exemple : Tout frais compris" value={obs} onChange={(e) => setObs(e.target.value)} /></Champ>
      <p className="tarif-total">Total : {fcfa(total)}</p>
    </Fenetre>
  );
}
