ALTER TABLE {{%issue_user}} ADD isu_order int(11) NOT NULL DEFAULT '0' AFTER isu_assigned_date, ADD INDEX isu_order (isu_order);
UPDATE {{%issue_user}} set isu_order=isu_iss_id;
