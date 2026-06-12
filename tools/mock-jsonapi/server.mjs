import Koa from "koa";
import { hatchifyKoa } from "@hatchifyjs/koa";
import { belongsTo, hasMany, integer, string, text } from "@hatchifyjs/core";

// Simple object graph: authors -> posts -> comments.
const schemas = {
  Author: {
    name: "Author",
    attributes: {
      name: string({ required: true }),
      email: string(),
    },
    relationships: {
      posts: hasMany("Post"),
    },
  },
  Post: {
    name: "Post",
    attributes: {
      title: string({ required: true }),
      body: text(),
      rating: integer(),
    },
    relationships: {
      author: belongsTo("Author"),
      comments: hasMany("Comment"),
    },
  },
  Comment: {
    name: "Comment",
    attributes: {
      message: text({ required: true }),
    },
    relationships: {
      post: belongsTo("Post"),
    },
  },
};

const PORT = Number(process.env.PORT ?? 3010);

const app = new Koa();
const hatchedKoa = hatchifyKoa(schemas, {
  prefix: "/api",
  database: { dialect: "sqlite", storage: ":memory:" },
});

app.use(hatchedKoa.middleware.allModels.all);

await hatchedKoa.modelSync({ alter: true });

const create = (type, body) =>
  fetch(`http://localhost:${PORT}/api/${type}`, {
    method: "POST",
    headers: { "Content-Type": "application/vnd.api+json" },
    body: JSON.stringify(body),
  }).then(async (response) => {
    const document = await response.json();
    if (!response.ok) {
      throw new Error(`Seeding ${type} failed: ${JSON.stringify(document)}`);
    }
    return document.data.id;
  });

const resource = (type, attributes, relationships) => ({
  data: { type, attributes, ...(relationships ? { relationships } : {}) },
});

const toOne = (type, id) => ({ data: { type, id } });

async function seed() {
  const authors = ["Ada Lovelace", "Grace Hopper", "Edsger Dijkstra"];
  let postNumber = 0;

  for (const [index, name] of authors.entries()) {
    const authorId = await create(
      "authors",
      resource("Author", {
        name,
        email: `${name.toLowerCase().replace(/\W+/g, ".")}@example.test`,
      }),
    );

    for (let i = 1; i <= 4; i++) {
      postNumber++;
      const postId = await create(
        "posts",
        resource(
          "Post",
          {
            title: `Post ${postNumber} by ${name.split(" ")[0]}`,
            body: `Body of post ${postNumber}.`,
            rating: ((postNumber * 7) % 5) + 1,
          },
          { author: toOne("Author", authorId) },
        ),
      );

      for (let j = 1; j <= 2; j++) {
        await create(
          "comments",
          resource(
            "Comment",
            { message: `Comment ${j} on post ${postNumber}.` },
            { post: toOne("Post", postId) },
          ),
        );
      }
    }
  }

  console.log(
    `Seeded ${authors.length} authors, ${postNumber} posts, ${postNumber * 2} comments.`,
  );
}

app.listen(PORT, async () => {
  await seed();
  console.log(`Mock JSON:API (hatchify) listening on http://localhost:${PORT}/api`);
});
