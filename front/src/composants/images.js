// Redimensionne une image choisie par l'utilisateur avant l'envoi (connexions lentes, base légère)
export function preparerImage(fichier, largeurMax = 600) {
  return new Promise((resoudre, rejeter) => {
    if (!/^image\/(png|jpeg|webp)$/.test(fichier.type)) { rejeter(new Error('Choisissez une image PNG ou JPEG.')); return; }
    const lecteur = new FileReader();
    lecteur.onerror = () => rejeter(new Error("Impossible de lire l'image."));
    lecteur.onload = () => {
      const img = new Image();
      img.onerror = () => rejeter(new Error('Image illisible.'));
      img.onload = () => {
        const echelle = Math.min(1, largeurMax / Math.max(img.width, img.height));
        const c = document.createElement('canvas');
        c.width = Math.round(img.width * echelle); c.height = Math.round(img.height * echelle);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        let d = c.toDataURL('image/png');                       // garde la transparence (cachet, signature)
        if (d.length > 550000) d = c.toDataURL('image/jpeg', 0.85);
        if (d.length > 590000) { rejeter(new Error('Image trop lourde, même réduite.')); return; }
        resoudre(d);
      };
      img.src = lecteur.result;
    };
    lecteur.readAsDataURL(fichier);
  });
}
