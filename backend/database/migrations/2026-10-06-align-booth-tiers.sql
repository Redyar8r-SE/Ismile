-- Keep old package/request history and existing prices; align new bookings.
ALTER TABLE sponsor_packages ADD COLUMN booth_tier ENUM('platinum','gold','silver','bronze') NULL AFTER style;

INSERT INTO sponsor_packages (id, kind, name_en, name_ar, name_ku, price, places, style, booth_tier, status, sort_order, created_at, updated_at)
VALUES
 ('platinum','sponsor','Platinum','البلاتيني','پلاتین',0,6,'tc-plat','platinum','active',1,NOW(),NOW()),
 ('gold','sponsor','Gold','الذهبي','زێڕ',0,7,'tc-gold','gold','active',2,NOW(),NOW()),
 ('silver','sponsor','Silver','الفضي','زیو',0,21,'tc-silver','silver','active',3,NOW(),NOW()),
 ('bronze','sponsor','Bronze','البرونزي','برۆنز',0,10,'tc-bronze','bronze','active',4,NOW(),NOW()),
 ('booth-platinum','booth','Platinum','البلاتيني','پلاتین',0,6,'tc-plat','platinum','active',1,NOW(),NOW()),
 ('booth-gold','booth','Gold','الذهبي','زێڕ',0,7,'tc-gold','gold','active',2,NOW(),NOW()),
 ('booth-silver','booth','Silver','الفضي','زیو',0,21,'tc-silver','silver','active',3,NOW(),NOW()),
 ('booth-bronze','booth','Bronze','البرونزي','برۆنز',0,10,'tc-bronze','bronze','active',4,NOW(),NOW())
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), name_ar=VALUES(name_ar), name_ku=VALUES(name_ku),
 places=VALUES(places), style=VALUES(style), booth_tier=VALUES(booth_tier), status='active', sort_order=VALUES(sort_order), updated_at=NOW();

UPDATE sponsor_packages SET status='hidden', updated_at=NOW()
WHERE (kind='sponsor' AND id NOT IN ('platinum','gold','silver','bronze'))
   OR (kind='booth' AND id NOT IN ('booth-platinum','booth-gold','booth-silver','booth-bronze'));
