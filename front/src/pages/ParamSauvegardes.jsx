import { useState } from 'react';
import { api } from '../api.js';
import { useDonnees, useMessage, Chargement, Alerte, formatDate } from '../composants/commun.jsx';

const taille = (o) => !o ? '—' : o > 1048576 ? `${(o / 1048576).toFixed(1).replace('.', ',')} Mo` : `${Math.round(o / 1024)} Ko`;

export default function ParamSauvegardes() {
  const message = useMessage();
  const d = useDonnees(() => api.get('admin/sauvegardes'), []);
  const [travail, setTravail] = useState('');
  const telecharger = async () => {
    setTravail('telecharger');
    try { const nom = await api.telecharger('admin/sauvegarde_telecharger'); message(`Sauvegarde téléchargée : ${nom}`); d.recharger(); }
    catch (x) { message(x.message, 'erreur'); } finally { setTravail(''); }
  };
  const envoyer = async () => {
    setTravail('envoyer');
    try { const r = await api.post('admin/sauvegarde_envoyer'); message(`Sauvegarde envoyée à ${r.destinataire}.`); }
    catch (x) { message(x.message, 'erreur'); } finally { setTravail(''); d.recharger(); }
  };
  if (d.charge && !d.donnees) return <Chargement />;
  if (d.erreur) return <Alerte>{d.erreur}</Alerte>;
  const derniere = d.donnees.historique.find((h) => h.type === 'NOCTURNE');
  return (
    <div>
      <h2 className="titre-section">Sauvegardes de la base</h2>
      <p className="discret texte-explicatif">Une sauvegarde contient toutes les données du réseau : écoles, élèves, notes, paiements, comptes et journal. Elle permet de tout restaurer en cas de problème.</p>
      <div className="grille-fiche">
        <article className="carte-fiche">
          <div className="carte-fiche-titre"><h2>Sauvegarde automatique</h2></div>
          {d.donnees.email_configure ? (
            <>
              <p>Chaque nuit vers 2 h, une sauvegarde est envoyée par e-mail à <strong>{d.donnees.destinataire}</strong>.</p>
              {derniere ? (
                <p className={derniere.statut === 'REUSSIE' ? 'texte-ok' : 'texte-retard'}>
                  Dernière : {derniere.statut === 'REUSSIE' ? 'réussie' : 'échec'} le {formatDate(derniere.cree_le)}
                  {derniere.statut === 'ECHEC' && derniere.message ? ` (${derniere.message})` : ''}
                </p>
              ) : <p className="discret">Aucune sauvegarde nocturne pour l'instant : la première aura lieu cette nuit.</p>}
              <button className="bouton" onClick={envoyer} disabled={!!travail}>{travail === 'envoyer' ? 'Envoi…' : 'Envoyer une sauvegarde maintenant'}</button>
            </>
          ) : (
            <Alerte type="info">La sauvegarde automatique n'est pas encore activée. Il faut renseigner deux réglages sur Railway : la clé du service d'envoi d'e-mails et l'adresse qui recevra les sauvegardes.</Alerte>
          )}
        </article>
        <article className="carte-fiche">
          <div className="carte-fiche-titre"><h2>Sauvegarde à la demande</h2></div>
          <p>Télécharge immédiatement une copie complète sur cet appareil. Conservez-la en lieu sûr (clé USB, disque dur, Google Drive).</p>
          <button className="bouton bouton-principal" onClick={telecharger} disabled={!!travail}>{travail === 'telecharger' ? 'Préparation…' : 'Télécharger une sauvegarde'}</button>
        </article>
      </div>
      <h3 className="titre-sous-section">Historique</h3>
      {d.donnees.historique.length === 0 ? <p className="discret">Aucune sauvegarde pour l'instant.</p> : (
        <div className="tableau-defilant">
          <table className="tableau">
            <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Résultat</th><th scope="col" className="nombre">Taille</th><th scope="col">Destination</th></tr></thead>
            <tbody>{d.donnees.historique.map((h) => (
              <tr key={h.id}>
                <th scope="row">{formatDate(h.cree_le)}</th>
                <td>{h.type === 'NOCTURNE' ? 'Automatique' : `Manuelle${h.auteur ? `, ${h.auteur}` : ''}`}</td>
                <td><span className={h.statut === 'REUSSIE' ? 'statut-nouveau' : 'statut-erreur'}>{h.statut === 'REUSSIE' ? 'Réussie' : 'Échec'}</span>
                  {h.statut === 'ECHEC' && h.message && <small className="note-ligne">{h.message}</small>}</td>
                <td className="nombre">{taille(h.taille_octets)}</td>
                <td>{h.destination ?? '—'}</td>
              </tr>))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
