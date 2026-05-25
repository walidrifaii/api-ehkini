-- Convert relative media paths to full https URLs on amcserver.
-- Adjust base if your files use /public/ instead of /storage/app/public/:
--   https://amcserver.com/app/taaruf/public/
-- Preview each table before UPDATE.

-- users.profile_image
SELECT id, profile_image,
       CONCAT('https://amcserver.com/app/taaruf/storage/app/public/', TRIM(LEADING '/' FROM profile_image)) AS new_url
FROM users
WHERE profile_image IS NOT NULL AND profile_image != ''
  AND profile_image NOT LIKE 'http%';

UPDATE users
SET profile_image = CONCAT('https://amcserver.com/app/taaruf/storage/app/public/', TRIM(LEADING '/' FROM profile_image))
WHERE profile_image IS NOT NULL AND profile_image != ''
  AND profile_image NOT LIKE 'http%';

-- posts.image
UPDATE posts
SET image = CONCAT('https://amcserver.com/app/taaruf/storage/app/public/', TRIM(LEADING '/' FROM image))
WHERE image IS NOT NULL AND image != ''
  AND image NOT LIKE 'http%';

-- stories.media (images + videos)
UPDATE stories
SET media = CONCAT('https://amcserver.com/app/taaruf/storage/app/public/', TRIM(LEADING '/' FROM media))
WHERE media IS NOT NULL AND media != ''
  AND media NOT LIKE 'http%';

-- gifts.image
UPDATE gifts
SET image = CONCAT('https://amcserver.com/app/taaruf/storage/app/public/', TRIM(LEADING '/' FROM image))
WHERE image IS NOT NULL AND image != ''
  AND image NOT LIKE 'http%';
