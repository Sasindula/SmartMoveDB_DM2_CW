<section class="panel" id="passenger-reviews">
  <div class="section-heading"><div><span class="eyebrow">MONGODB FEEDBACK</span><h2>My Reviews</h2></div></div>
  <?php if ($reviewableTrips): ?>
    <form class="passenger-edit-form" method="post" action="/SmartMoveDB/views/passenger_review_action.php">
      <input type="hidden" name="csrf_token" value="<?= ph($csrf) ?>">
      <label>Completed Trip
        <select name="trip_id" required>
          <?php foreach ($reviewableTrips as $trip): ?>
            <option value="<?= (int)$trip['trip_id'] ?>">Trip #<?= (int)$trip['trip_id'] ?> — <?= ph($trip['source']) ?> → <?= ph($trip['destination']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Rating
        <select name="rating" required>
          <?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>"><?= $rating ?> / 5</option><?php endfor; ?>
        </select>
      </label>
      <label>Your review<textarea name="comment" maxlength="1000" rows="3" required placeholder="Share your experience"></textarea></label>
      <button type="submit" class="button">Submit Review</button>
    </form>
  <?php else: ?><p class="muted">No completed, unreviewed trips available.</p><?php endif; ?>
  <div class="sm-mongo-list">
    <?php foreach ($myReviews as $review): ?>
      <article class="sm-mongo-item"><strong>Trip #<?= (int)($review['trip_id'] ?? 0) ?> · <?= (int)($review['rating'] ?? 0) ?>/5 ★</strong>
        <p><?= ph($review['comment'] ?? '') ?></p><small><?= ph(smMongoDate($review['created_at'] ?? null)) ?></small></article>
    <?php endforeach; ?>
    <?php if (!$myReviews): ?><p class="muted">You have not posted any reviews yet.</p><?php endif; ?>
  </div>
</section>
<section class="panel" id="travel-announcements">
  <div class="section-heading"><div><span class="eyebrow">MONGODB UPDATES</span><h2>Travel Announcements</h2></div></div>
  <div class="sm-mongo-list">
    <?php foreach ($travelAnnouncements as $announcement): ?>
      <article class="sm-mongo-item"><strong><?= ph($announcement['title'] ?? '') ?></strong>
        <span class="badge"><?= ph($announcement['priority'] ?? 'Normal') ?></span>
        <p><?= ph($announcement['message'] ?? '') ?></p><small><?= ph(smMongoDate($announcement['published_date'] ?? null)) ?></small></article>
    <?php endforeach; ?>
    <?php if (!$travelAnnouncements): ?><p class="muted">No passenger announcements available.</p><?php endif; ?>
  </div>
</section>
<style>
.sm-mongo-list {display:grid;gap:12px;margin-top:16px}
.sm-mongo-item {border:1px solid #e3eaf0;border-radius:10px;padding:15px;background:#fff}
.sm-mongo-item p {margin:8px 0;overflow-wrap:anywhere}
.sm-mongo-item small {color:#657585}
.sm-mongo-item .badge {margin-left:8px}
</style>
